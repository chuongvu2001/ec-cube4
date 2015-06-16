<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube\Service;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class PluginService
{
    private $app;

    CONST CONFIG_YML = "config.yml";
    CONST EVENT_YML = "event.yml";

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function install($path)
    {
       $tmp = $this->createTempDir();

       $this->unpackPluginArchive($path,$tmp); //一旦テンポラリに展開
       $this->checkPluginArchiveContent($tmp);

       $config = $this->readYml($tmp.'/'.self::CONFIG_YML);
       $event = $this->readYml($tmp.'/'.self::EVENT_YML);
       $this->deleteFile($tmp); // テンポラリのファイルを削除

       $this->checkSamePlugin($config['code']);

       $pluginBaseDir =  $this->calcPluginDir($config['name'])  ;
       $this->createPluginDir($pluginBaseDir); // 本来の置き場所を作成


       $this->unpackPluginArchive($path,$pluginBaseDir); // 問題なければ本当のplugindirへ

       $this->registerPlugin($config,$event); // dbにプラグイン登録
       $this->callPluginManagerMethod( $config,'install' ); 

    }

    public function uninstall(\Eccube\Entity\Plugin $plugin)
    {
       $pluginDir = $this->calcPluginDir($plugin->getName());

       $this->callPluginManagerMethod( Yaml::Parse($pluginDir.'/'.self::CONFIG_YML ),'uninstall' ); 
       $this->unregisterPlugin($plugin);
       $this->deleteFile($pluginDir); 

    }
    public function enable(\Eccube\Entity\Plugin $plugin,$enable=true)
    {
       $pluginDir = $this->calcPluginDir($plugin->getName());
       $em = $this->app['orm.em'];
       $plugin->setEnable($enable ? 1:0);
       $em->persist($plugin); 
       $em->flush(); 
       $this->callPluginManagerMethod( Yaml::Parse($pluginDir.'/'.self::CONFIG_YML ) ,$enable ? 'enable':'disable'    ); 
    }
    public function disable(\Eccube\Entity\Plugin $plugin)
    {
       $this->enable($plugin,false);
    }
    public function update(\Eccube\Entity\Plugin $plugin,$path)
    {
       $tmp = $this->createTempDir();

       $this->unpackPluginArchive($path,$tmp); //一旦テンポラリに展開
       $this->checkPluginArchiveContent($tmp);

       $config = $this->readYml($tmp.'/'.self::CONFIG_YML);
       $event = $this->readYml($tmp."/event.yml");

       if($plugin->getCode() != $config['code']){
           throw new \Exception("new/old plugin code is different.");
       }
       if($plugin->getName() != $config['name']){
           throw new \Exception("new/old plugin name is different.");
       }

       $pluginBaseDir =  $this->calcPluginDir($config['name'])  ;
       $this->deleteFile($tmp); // テンポラリのファイルを削除


       $this->unpackPluginArchive($path,$pluginBaseDir); // 問題なければ本当のplugindirへ

       $this->updatePlugin($plugin,$config,$event); // dbにプラグイン登録
       $this->callPluginManagerMethod( $config,'update' ); 
    }






    public function calcPluginDir($name)
    {
        return __DIR__.'/../../../app/Plugin'.'/'.$name;
    }
    public function checkSamePlugin($code)
    {
        $em = $this->app['orm.em'];

        $rep=$em->getRepository('Eccube\Entity\Plugin') ;
        if(count($rep->getPluginByCode($code,true))){
            throw new \Exception('plugin already installed.');
        }

    }
    public function checkPluginArchiveContent($dir)
    {
       $meta = $this->readYml($dir."/config.yml");
       if(!is_array($meta)) {
           throw new \Exception("config.yml not found or syntax error");
       }
       if(!$this->checkSymbolName($meta['code'])){
           throw new \Exception("config.yml code  has invalid_character(\W) ");
       }
       if(!$this->checkSymbolName($meta['name'])){
           throw new \Exception("config.yml name  has invalid_character(\W)");
       }
       if(strlen($meta['event']) and !$this->checkSymbolName($meta['event'])){
           throw new \Exception("config.yml event has invalid_character(\W) ");
       }
       if(!strlen($meta['version'])){
           throw new \Exception("config.yml version not defined. ");
       }
    }

    public function checkSymbolName($string)
    {
       return strlen($string) < 256 && preg_match('/^\w+$/',$string);
       // plugin_nameやplugin_codeに使える文字のチェック
       // a-z A-Z 0-9 _ 
       // ディレクトリ名などに使われれるので厳しめ
    }

    public function readYml($yml)
    {
        return Yaml::Parse($yml);
    }
    public function createTempDir()
    {
        $d=(sys_get_temp_dir().'/'.sha1( openssl_random_pseudo_bytes(16) ));
        $b=mkdir($d,0777);
        if(!$b){
            throw new \Exception($php_errormsg);
        }
        return $d;
        
    }
    public function createPluginDir($d)
    {
        $b=mkdir($d);
        if(!$b){
            throw new \Exception($php_errormsg);
        }
    }
    public function unpackPluginArchive($archive,$dir)
    {
        $tar = new \Archive_Tar($archive, true);
        $tar->setErrorHandling(PEAR_ERROR_EXCEPTION);
        $result = $tar->extractModify($dir . '/', '');
    }

    public function updatePlugin(\Eccube\Entity\Plugin $plugin,$meta,$event_yml)
    {
        $em = $this->app['orm.em'];
        $em->getConnection()->beginTransaction(); 
        $plugin->setVersion($meta['version']) 
               ->setClassName($meta['event'])
               ->setName($meta['name']);

        $rep=$em->getRepository('Eccube\Entity\PluginEventHandler');
        foreach($event_yml as $event=>$handlers){
            foreach($handlers as $handler){
                if( !$this->checkSymbolName($handler[0]) ){
                    throw new \Exception("Handler name format error");
                }
                $peh = $rep->findBy(array('del_flg'=>0,'plugin_id'=> $plugin->getId(),'event' => $event ,'handler' => $handler[0] ));
                if(!$peh){ // 新規にevent.ymlに定義されたハンドラなのでinsertする
                    $peh = new \Eccube\Entity\PluginEventHandler();
                    $peh->setPlugin($plugin)
                        ->setEvent($event)
                        ->setdelFlg(0)
                        ->setHandler($handler[0])
                        ->setHandlerType($handler[1])
                        ->setPriority($em->getRepository('Eccube\Entity\PluginEventHandler')->calcNewPriority( $event,$handler[1]) );
                    $em->persist($peh);
                    $em->flush(); 

                }
            }
        }

        $em->persist($plugin); 
        $em->flush(); 
        $em->getConnection()->commit();
    }
    public function registerPlugin( $meta ,$event_yml )
    {

        $em = $this->app['orm.em'];
        $em->getConnection()->beginTransaction(); 
        $p = new \Eccube\Entity\Plugin();
        $p->setName($meta['name'])
          ->setEnable(1)
          ->setClassName($meta['event'])
          ->setVersion($meta['version'])
          ->setDelflg(0)
          ->setSource(0)
          ->setCode($meta['code']);

        $em->persist($p); 
        $em->flush(); 

        foreach($event_yml as $event=>$handlers){
            foreach($handlers as $handler){
                if( !$this->checkSymbolName($handler[0]) ){
                    throw new \Exception("Handler name format error");
                }
                $peh = new \Eccube\Entity\PluginEventHandler();
                $peh->setPlugin($p)
                    ->setEvent($event)
                    ->setdelFlg(0)
                    ->setHandler($handler[0])
                    ->setHandlerType($handler[1])
                    ->setPriority($em->getRepository('Eccube\Entity\PluginEventHandler')->calcNewPriority( $event,$handler[1]) );
                $em->persist($peh);
                $em->flush(); 
            }
        }

        $em->persist($p); 
        $em->flush(); 
        $em->getConnection()->commit();

    }

    public function unregisterPlugin(\Eccube\Entity\Plugin $p){
        $em = $this->app['orm.em'];
        $em->getConnection()->beginTransaction(); 

        $p->setDelFlg(1)->setEnable(0);

        $rep=$em->getRepository('Eccube\Entity\PluginEventHandler');
        foreach($rep->findBy(array('plugin_id'=> $p->getId()  )) as $peh ) {
            // Assosiationを経由して子エンティティが取れるはずなのだけどうまく動作していないので
            // 一旦ベタな書き方で回避
            $peh->setDelFlg(1); 
            $em->persist($peh); 
        }

        $em->persist($p); 
        $em->flush(); 

        $em->getConnection()->commit();
    }

    public function callPluginManagerMethod($meta,$method)
    {
        $class = '\\Plugin'.'\\'.$meta['name'].'\\' .'PluginManager';
        if(class_exists($class)){
            $installer = new $class(); // マネージャクラスに所定のメソッドがある場合だけ実行する
            if(method_exists(  $installer , $method )){
                $installer->$method($meta,$this->app);
            }
        }
    }

    public function deleteFile($path)
    {
        $f=new Filesystem();
        return $f->remove($path);
    }
}
