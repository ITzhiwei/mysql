# 版本注册
php5.4、5.5、5.6、7.*
# 安装
composer require lipowei/Db
# 使用
引入 vendor 目录下的 autoload.php  
```
<?php
require_once 'vendor/autoload.php';
use lipowei\Db\Db;  
/** 初始化 **/
$config = [  
           //主数据库，如果读写分离，主数据库服务器只写不读；如果读写不分离，将其他服务器的数据库链接信息加入host数组里面去就行了  
           'host' => ['localhost'],  
           'port' => ['3306'],  
           'database' => ['test'],  
           'username' => ['root'],  
           'password' => [''],  
           //比utf8不支持小表情存数据库  
           'charset' => 'utf8mb4',  
           //是否显示SQL错误信息  
           'sqlDebug' => true,  
           //数据库部署方式:0单一服务器  1分布式（2个或以上）  
           'deploy'  => 0,  
           //数据库读写是否分离，分布式有效  
           'rwSeparate' => false,  
           //从数据库，从数据库服务器只读不写；注意：只有在读写分离才将链接信息写在下面  
           'slaveHost' => [],  
           'slavePort' => [],  
           'slaveDatabase' => [],  
           'slaveUsername' => [],  
           'slavePassword' => [],
         ];
//将配置信息传入类
Db::$config = $config;
/** 当使用这俩行代码时，只需要执行一次，配置信息保存以后无需再进行任何初始化
//重要：将配置信息保存到文件内，默认目录为 vendor 的同级目录 config 内的 lipoweiDB.php；如果不是使用 composer 安装的该DB库则无法使用，因为该功能有依赖
Db::$configSave = true;
Db::crateSqlObj($config);
**/
/** 初始化结束 **/

//经典用法

?>
```         
