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

//原生用法
//read查询使用 ? 占位进行预处理，可防止SQL注入；Db::read() 返回的是二维数组结果集或者无结果空数组
$row = Db::read("SELECT * FROM article WHERE id<? AND title like ?", [50,'%张三%']);
//write 写数据，更新、删除、插入等使用；执行返回的是受影响条数(新增条数、更新条数、删除条数)；
$res = Db::write("INSERT INTO `article`(title,content,user_id) VALUES(?, ?, ?)", [$title, $content, $uid]);

//经典用法，全部自动使用预处理防SQL注入，自动加反引号防关键词冲突，这俩个和上面原生执行的返回结果一样
$row = Db::table('article')->where([ ['id', '<', 50], ['title', 'like', '%张三%'] ])->select();
$res = Db::table('article')->insert(['title'=>$title, 'content'=>$content, 'user_id'=>$uid]);
//获取单个数据，下面真实执行语句： SELECT `title` FROM `article` WHERE `id`=50 LIMIT 0,1  
$title = Db::table('article')->where('id', 50)->value('title');
//获取单行数据，返回的是一维数组 ['title'=>'标题', ...]
$article = Db::table('article')->where('id', 50)->select('*', false);
//更新 id<50 的行进行更新，返回受影响条数
$res = Db::table('article')->where('id', '<', 50)->update(['title'=>'newTitle']);
//和上面执行一样，第2个参数为更新白名单，不存在白名单不更新
$res = Db::table('article')->where('id', '<', 50)->update($_POST, 'title');
//删除，默认条件下必须设置where才可以删除，否则报错；如果要强制无条件删除，设置 ->delete(true)
$res = Db::table('article')->where('id', '<', 50)->delete();

?>
```
# 手册
**::table($tableString, $transform = true)**  
$tableString 可以是单个表名，也可以是关联表，例如:  
$tableString = 'users a left join email b on(a.id=b.user_id)'  
这样 $tableString 就很灵活了，而且不管你怎么写，都会自动加反引号。  
$transform，是否为 $tableString 自动添加反引号。如果你确保自己不需要加反引号，可以设置为 false

**->where($where, $conditionOrValue = null, $value = null)**  
where 可以仅接受1个参数，这个参数必须是数组，一维数组或二维数组  
一维数组，如果是3个值，那么中间是作为条件，如：['name', '=', '张三']，俩个值则条件为"="号['name', '张三']  
二维数组，可以传入多个值，如：[ ['name', '张三'], ['age', '>', 18] ] //WHERE \`name\`='张三' AND \`age\`>18  

where 可以传入 $conditionOrValue 和 $value 如：  
where('name', '张三') 或者 where('name', '=', '张三')

**->select($field = '\*', $more = true)**  
$field 是需要查询的字段，默认是所有，即\*   
$more 默认为true，查询多行，返回二位数组结果集，设置为false时即为查询单行，即 LIMIT 0,1  返回一维数组结果集






