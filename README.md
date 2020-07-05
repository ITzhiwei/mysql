# php mysql 防SQL注入预处理封装库
# 版本支持
php5.6、7.*
# 安装
composer require tcwei/db
# 使用（支持分布式读写分离，可自动保存配置，无需初始化）
引入 vendor 目录下的 autoload.php  
```
<?php
require_once 'vendor/autoload.php';
use tcwei\Db\Db;  
/** 初始化 **/
$config = [//可将配置信息保存起来，以后无需配置就可直接使用：使用 Db::$configSave = true; + Db::crateSqlObj($config);下面有详细介绍 
           //主数据库，如果读写分离，主数据库服务器只写不读；如果读写不分离，将其他服务器的数据库链接信息加入host数组里面去就行了  
           'host' => ['localhost'],  
           'port' => ['3306'],  
           'database' => ['test'],  
           'username' => ['root'],  
           'password' => [''],  
           //utf8mb4相对utf8来说，utf8mb4支持emoji，但占的内存大一点
           'charset' => 'utf8mb4',  
           //是否显示SQL错误信息  
           'sqlDebug' => true,  
           //数据库部署方式:0单一服务器  1多个 mysql 服务器（2个或以上）  
           'deploy'  => 0,  
           //数据库读写是否分离，分布式有效  
           'rwSeparate' => false,  
           //在读写分离的环境下，是否开启一旦表有写操作，本次请求的后续操作涉及到该表的都使用写句柄进行操作，避免数据在读库尚未同步完成导致数据不一致
           'readSelectMaster' => false,
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
//重要：将配置信息保存到文件内，默认目录为 vendor 的同级目录 config 内的 tcweiDB.php；如果不是使用 composer 安装的该DB库则无法使用，因为该功能有依赖
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

//获取单行数据，返回的是一维数组 ['title'=>'标题', 'content'=>'文章内容']
$article = Db::table('article')->where('id', 50)->find('title,content');

//更新 id<50 的行进行更新，返回受影响条数
$res = Db::table('article')->where('id', '<', 50)->update(['title'=>'newTitle']);

//和上面执行一样，第2个参数为更新白名单，不存在白名单不更新
$res = Db::table('article')->where('id', '<', 50)->update($_POST, 'title');

//删除，默认条件下必须设置where才可以删除，否则报错；如果要强制无条件删除，设置 ->delete(true)
$res = Db::table('article')->where('id', '<', 50)->delete();

?>
```         

# 手册
**::transaction($callback, $errorCallback = null)**  
```
//事务执行
Db::transaction(function(){
                //写你的业务逻辑代码
               }, function($error){
                 //事务执行失败，在这里你选择是否结束程序执行。若要结束执行exit即可
                 //$error->getMessage(); $error是获取到的 \Exception $error
               });
```

**::table($tableString, $transform = true)**  
* $tableString 可以是单个表名，也可以是关联表，例如:  
  $tableString = 'users a left join email b on(a.id=b.user_id)'  
  这样 $tableString 就很灵活了，而且不管你怎么写，都会自动加反引号。  
* $transform，是否为 $tableString 自动添加反引号。如果你确保自己不需要加反引号，可以设置为 false  

**JOIN表相关**
```
所有的表关联都可直接写在 table() 内，可自动识别加反引号，方便快捷，如：
Db::table('article left join article_seo on(article.id=article_seo.article_id)')
Db::table('article inner join article_seo on(article.id=article_seo.article_id)')
Db::table('article right join article_seo on(article.id=article_seo.article_id)')
Db::table('article a left join article_seo b on(a.id=b.article_id) left join users c on(a.user_id=c.id)')
```

**->where($where, $conditionOrValue = null, $value = null)**  
* where() 可以仅接受1个参数，这个参数必须是数组，一维数组或二维数组  
 一维数组，如果是3个值，那么中间是作为条件，如：['name', '=', '张三']，俩个值则条件为"="号['name', '张三']  
 二维数组，可以传入多个值，如：[ ['name', '张三'], ['age', '>', 18] ] //WHERE \`name\`='张三' AND \`age\`>18  

* where() 可以传入 $conditionOrValue 和 $value 如：  
  ->where('name', '张三') 或者 ->where('name', '=', '张三')  
  
* where() 对 IN 、NOT IN、BETWEEN 、NOT BETWEEN 的支持，用法：
```
  ->where('id', 'IN', [1,2,3,4])       或 ->where([ ['id', 'IN', [1,2,3,4]] ])
  ->where('id', 'NOT IN', [1,2,3,4])   或 ->where([ ['id', 'IN', [1,2,3,4]] ])
  ->where('id', 'BETWEEN', [1,3])      或 ->where([ ['id', 'BETWEEN', [1,3]] ])
  ->where('id', 'NOT BETWEEN', [1,3])  或 ->where([ ['id', 'NOT BETWEEN', [1,3]] ])
  //$where可以是空数组，方便做不确定多条件的查询，如：
  $whereArray = [];
  if(!empty($_POST['age'])){
      $whereArray[] = ['age', '=', $_POST['age']];
  }
  if(!empty($_POST['name'])){
      $whereArray[] = ['name', 'like', $_POST['name']];
  }
  $row = Db::table('users')->where($whereArray)->select();  
```

**->whereOr($where, $conditionOrValue = null, $value = null)**  
* 三个参数和->where一样，说说不同的地方  
* 如果$where是二维数组，那么前面连接符为AND，数组间的连接符为OR，如： 
``` 
// WHERE `sex`='男' AND (`name`='张三' OR `age`>18 OR `name`='李四')  
->where('sex', '男')->whereOr([ ['name', '张三'], ['age', '>', 18], ['name', '李四'] ])   
```
* 如果传入2到3个参数，那么连接符为 OR ，如：  
```
// WHERE `sex`='男' OR `name`='张三'  
->where('sex', '男')->whereOr('name', '张三')  
```
```
whereOr 选择二维数组和2到3个参数的组合，可以拼出任意OR AND条件语句
```
**->likeConcat($field, $concat, $concatValue = [])**  
* $field 需要模糊查询的字段名  
* $concat 例如： $concat = "'%',?,'%'"  需要使用 ? 来做占位符，然后将?对应的数据放到第3个参数里面去  
* $concatValue 是数组，存放 $concat 对应的占位符 ? 内容；  
```
->likeConcat('content', "'%',?,'%'", [$_POST['search']]);作用是防止需要模糊查询的内容里面包含 ? _ 这些特殊字符
```
**->limit($start = 1, $length = null)**
* $start 如果在$length为空的情况下，相当于 LIMIT 0,$start，反之即是从第几行开始取数据  
* $length 取多少行数据  

**order($field, $rank = 'ASC')**
* string|array $field string例子：->order('id', 'DESC')
* string:default:asc $rank 如果设置为 false ,则可以使用自定义排序：$field = "FIELD('id', 7, 9, 5) ASC"
```
//字符串模式例子
->order('time', 'desc');
//数组模式例子
DESC：
->order(['time'=>'desc']) 或者 ->order(['time'], 'DESC')
->order(['time'=>'desc'， 'id'=>'desc']) 或者 ->order(['time', 'id'], 'DESC')
ASC：
->order(['time']) 效果： time ASC
->order(['time'， 'id']) 效果： time ASC,id ASC
```
**->group($group)**  
* $group 字符串或数组，单个字段分组时使用字符串，多个字段分组使用数组

**->hvaing($hvaing)**  
* $hvaing 字符串

**->noQuery()**  
* 使Db类不进行真正的执行，返回sql语句，如：  
```
$res = Db::table('article')->noQuery()->where(['id', '<', 100])->order(['id'=>'desc', 'title'])->group('type')->limit(0, 10)->select();
echo $res;//SELECT * FROM `article` WHERE `id` < ? GROUP BY `type` ORDER BY `id` desc ,`title` ASC LIMIT 0,10 参数：[100]
``` 

**->insert($array, $columns = [], $filter = false, $type = null)**  
* $array 必须是一维数组，key是字段，value是插入字段的值  
* $columns 是插入白名单，如果设置了，那么不存在白名单的都会被过滤,如：  
```
Db::table('article')->insert($_POST, ['title', 'content', 'time']);//仅允许插入title、content、time
```
* $filter 设置是否自动过滤不存在的字段数据，如：insert($_POST, [], true);  
* $type 一般来说无需理会，想了解的可以看源码  
* 成功执行返回1,失败0  


**->insertAll($array, $columns = [], $filter = false, $type = null)**  
* $array 二维数组，如 [ ['username'=>'张三', 'tel'=>'15210000000'],['username'=>'李四', 'tel'=>'15210000001'] ]
* 其余参数和 insert 一致 
```
Db::table('article')->insertAll([ ['username'=>'张三', 'tel'=>'15210000000'],['username'=>'李四', 'tel'=>'15210000001'] ]);
```
* 返回插入条数   

**::$insertId**  
```
insert后若需要获取自增ID，调用：
$id = Db::$insertId;
```

**->delete($all = false, $type = null)**  
* 默认无需传参数，防止误删整表数据，如果没有where存在的情况下，delete不会执行，会报错，若要强制删除整表数据，$all传 true 即可  
* $type 一般来说无需理会，想了解的可以看源码  
* 返回的结果是删除的行数  

**->update($array, $columns = [], $filter = false, $all = false, $type = null)**  
* $array 一维数组，需要更新的数据  
* $columns 更新白名单，$array 是 $_POST，可能存在其他字段数据，但是不允许被更新的，那么 $columns 应用起来就很方便了  
* $filter 和 insert() 中的作用一样  
* $all 和 delete() 中的一样，防止不小心整表更新，若要强制删除整表数据，$all 传 true 即可  
* $type 一般来说无需理会，想了解的可以看源码  
* 返回的结果是更新的行数
```
更新系列操作的还有自增和自减操作
Db::table('users')->where('id', 1)->setInc('money');//用户余额+1
Db::table('users')->where('id', 1)->setInc('money', 5);//用户余额+5
Db::table('users')->where('id', 1)->setDec('money');//用户余额-1
Db::table('users')->where('id', 1)->setDec('money', 5);//用户余额-5
如没有设置 where，会被拦截并中断程序执行，如果需要强制整表更新，传入第3个参数为true，如：
Db::table('users')->setInc('money', 5, true);//全部用户的余额增加5

多字段自增自减（此处只列出自增例子，自减用法一样）：
Db::table('users')->where('id', 1)->setIncMany(['money', 'age']);//用户余额和年龄都+1
Db::table('users')->where('id', 1)->setIncMany(['money', 'age'], [100,1]);//用户余额+100 年龄+1
```

**->select($field = '\*', $more = true)**  
* $field 是需要查询的字段，默认是所有，即\*   
* $more 默认为true，查询多行，返回二位数组结果集，设置为false时即为查询单行，即 LIMIT 0,1  返回一维数组结果集 

**->find($field = '\*')**
* 注：find 是单行查询，效果等同 ->select($field, false)
* $field 是需要查询的字段，默认是所有，即\*   

**->value($field)**
```
//获取单个数据
$res = Db::table('users')->where('id', 1)->value('tel');
```
**->count()**  
```
//获取name是张三的用户有多少个
$num = Db::table('users')->where('name', '张三')->cound();
```
**->sum($field)**
```
//获取所有叫张三的用户总余额
$moneyAll = Db::table('users')->where('name', '张三')->sum('money');
```
**->avg($field)**
```
//获取所有叫张三的用户的平均年龄
$moneyAll = Db::table('users')->where([['name', '=', '张三']])->avg('age');
```
**->max($field)**
```
//获取所有叫张三的用户中的最大年龄是多少
$moneyAll = Db::table('users')->where(['name'=>'张三'])->max('age');
```
**->min($field)**
```
//获取所有叫张三的用户中的最小年龄是多少
$moneyAll = Db::table('users')->where(['name', '=', '张三'])->min('age');
```
**::$sqlStr**
```
//获取最近一次合成的sql语句; 和 ->noQuery() 不同的地方在于 Db::$sqlStr 是获取最近合成的SQL语句，不管最近一次的SQL有没有真正执行
$sqlStr = Db::$sqlStr;
```
# 多数据库分布式
如果都是可读可写的，将信息写在host系列里面去即可，如:
```
'host' => ['192.168.1.1', '192.168.1.2' ...],
'username'=>['root', 'root' ...],
...
'deploy' => 1
```
# 读写分离
```
'deploy'  => 1,
'rwSeparate' => true,
//一维数组，有多少个写多少个值在数组里面，下同
'slaveHost' => [...],
'slavePort' => [...],
'slaveDatabase' => [...],
'slaveUsername' => [...],
'slavePassword' => [...]'
```
某些环境需要直接从写库（主库）中读取数据，如刚进行写操作（插入或更新）时，读库（从库）数据还没同步完成，你可以使用->master()，如：
```
Db::table('users')->where('id', 1)->update(['name' => 'tcwei']);
$name = Db::table('users')->master()->where('id', 1)->value('name');
```
如果你嫌麻烦，可以配置一个参数：如果某表进行了写操作，本次请求的后续查询操作都会强制使用主库进行查询。
```
'readSelectMaster' => true
```
# 额外说明
该库使用的是 mysqli 模式；  
这里说说 **mysqli** 与 **PDO** 重要区别。  
**兼容**：mysqli 仅支持 mysql 数据库，PDO 支持大部分数据库，PDO完胜；  
**性能**：本人在 PHP7.3.5 + MYSQL5.7.25 环境测试下：  
都是使用预处理模式操作最基本的insert和select，每次循环500次，运行10次计算数据平均值得出结果：  
**insert**下，mysqli 仅比 PDO 快**1.59%**，基本可忽略。  
**select**下，mysqli 比 PDO 快**18.84%**  
由于PDO要兼容大部分数据库，所以性能上是不及mysqli的  
若你的项目是仅使用 mysql 的同时追求毫秒级别的性能优化，那么 mysqli 模式或许更合适你