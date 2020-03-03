<?php
// +----------------------------------------------------------------------
// | 全部是预处理模式，安全防SQL注入，自动加反引号防止MYSQL关键词冲突
// +----------------------------------------------------------------------
// | 版本支持：php5.4 5.5 5.6  PHP7.*
// +----------------------------------------------------------------------
// | Author: lipowei <2394599321@qq.com>
// +----------------------------------------------------------------------

namespace lipowei\Db;
//lipowei\configClass\Config 只有通过 composer 下载安装才拥有该类
use lipowei\configClass\Config;


class Db{

    /**
     * 配置文件名，不包含后缀，后缀为".php"
     * @var string
     */
    public static $configFileName = 'lipoweiDB';

    /**
     * 错误日志存放目录; vendor的同级目录log下的mysql目录，如果是 /../../../../log/mysql/ ，会自动在前面加上 __DIR__，不在这里加__DIR__，是为了兼容
     * @var string
     */
    public static $logDir = '/../../../../log/mysql/';

    /**
     * 配置信息
     * @var array
     */
    public static $config = [];
    /**
     * 是否将配置信息写进配置文件内；若写进，配置信息会自动从配置文件中提取，可以直接使用 DB 类无需再输入配置信息；使用 composer 下载本DB库时才可使用该配置，若是单独使用该DB库则无法将配置信息写进配置文件
     * @var bool
     */
    public static $configSave = false;
    /**
     * 是否显示SQL错误信息，需要在配置文件设置或者传入配置参数配置，若没有设置则取此处的默认值
     * @var bool
     */
    public static $sqlDebug = true;
    /**
     * mysql write 链接；单一数据库时负责读写，读写分离时负责写
     * @var object mysqlConnectObj
     */
    public static $writeMysql = null;
    /**
     * mysql read 链接；读写分离时负责读
     * @var object mysqlConnectObj
     */
    public static $readMysql = null;
    /**
     * 当前的sql语句
     * @var string
     */
    public static $sqlStr = null;
    /**
     * 最近一次成功插入的自增ID；获取此ID时，在操作插入数据时，需要判断执行返回结果不为0后才获取该自增ID，不然获取得到的 $insertId 要么是 null，要么是上一次的插入产生的ID
     * @var int
     */
    public static $insertId = null;
    /**
     * 当前是否处于事务
     * @var bool
     */
    public static $startAffairBool = false;
    /**
     * @var $this
     */
    public static $obj = null;
    /**
     * 表名或者各表名及关联
     * @var string
     */
    protected $table = null;
    /**
     * 当前拼接的where语句
     * @var string
     */
    protected $whereStr = '';
    /**
     * 当前存储的预处理参数，不只是where中的数据，还有其他地方的也放到该处
     * @var array
     */
    public $whereValue = [];
    /**
     * 存放当前拼接的limit语句
     * @var string
     */
    protected $limitStr = '';
    /**
     * 存放当前的order语句
     * @var string
     */
    protected $orderStr = '';
    /**
     * @var string
     */
    protected $groupStr = '';
    /**
     * @var string
     */
    protected $havingStr = '';
    /**
     * 多数据库服务时是否强制选择 $writeMysql
     */
    protected static $selectMasterSql = false;
    /**
     * 多数据库服务时强制选择 $writeMysql 的表名存放处
     */
    protected static $selectMasterSqlTable = [];

    /**
     * 是否执行sql，如果只是想看下拼接的SQL语句是什么，设为false即可
     * @var bool
     */
    protected static $sqlQuery = true;

    /**
     * 所有操作都需要经过这里获取 mysql 链接句柄
     * @param string $sqlStr
     * @return object mysqlConnect
     */
    protected static function mysqli($sqlStr = 'insert'){
        $sqlCDUS = strtolower(substr(trim($sqlStr), 0, 6));
        $config = self::$config;
        if(empty($config)){
            $config = self::getSqlConfig();
        };
        if($sqlCDUS == 'select' && $config['deploy'] == 1 && $config['rwSeparate'] && self::$readMysql == null){
            //创建从数据库
            self::crateSqlObj($config, 'read');
        }elseif(self::$writeMysql == null){
            //创建主数据库
            self::crateSqlObj($config, 'write');
        }
        if($config['deploy'] == 1 && $config['rwSeparate']){
            //self::$selectMasterSql  是主动选择主库操作
            if($sqlCDUS == 'select' && self::$selectMasterSql == false){
                //readSelectMaster 开启了一但在多mysql服务的读写分离环境下，若那个表执行了写操作，该表的后续操作都在写句柄执行
                $thisObj = self::getThisObj();
                $table = $thisObj->table;//原生执行table=null，不支持写库读取模式
                if($config['readSelectMaster'] && self::$selectMasterSqlTable != [] && $table !== null){
                    $nawTable = self::getTableName($table);
                    $bool = false;
                    $oldTableArr = self::$selectMasterSqlTable;
                    foreach ($nawTable as $key=>$value){
                        if(in_array($value, $oldTableArr)){
                            $bool = true;
                            break;
                        }
                    }
                    if($bool){
                        //该表在本次程序执行中有过写操作，所以后续强制使用写句柄继续操作
                        return self::$writeMysql;
                    }else{
                        return self::$readMysql;
                    }
                }else{
                    return self::$readMysql;
                }
            }else{
                return self::$writeMysql;
            }
        }else{
            //只有一个mysql模式
            return self::$writeMysql;
        }
    }


    /**
     * @param array $connectConfig $connectConfig['host']['127.0.0.1'], $connectConfig['username']['root'], $connectConfig['password']['pwd'], $connectConfig['database']['test'], $connectConfig['port'][3369]
     * @param string $type 只要在读写分离的多数据库情况下使用，单数据库服务无须理会
     * @throws \Exception 数据库连接失败时抛出错误
     */
    public static function crateSqlObj($connectConfig = [], $type = 'write'){
        //获取配置信息
        if($connectConfig === []){
            //获取配置文件的信息
            $connectConfig = self::getSqlConfig();
        }else{
            if(self::$configSave){
                //将配置信息写进文件中，下次使用时不用再输入配置信息
                self::writeConfigToFile($connectConfig);
            }
        }
        //保存配置信息
        self::$config = $connectConfig;
        if(isset($connectConfig['sqlDebug'])){
            self::$sqlDebug = $connectConfig['sqlDebug'];
        };
        if($type == 'write'){
            if($connectConfig['deploy'] == 1) {
                $writeNum = array_rand($connectConfig['host']);
            }else{
                $writeNum = 0;
            }
            $sqlObj = new \mysqli($connectConfig['host'][$writeNum], $connectConfig['username'][$writeNum], $connectConfig['password'][$writeNum], $connectConfig['database'][$writeNum], $connectConfig['port'][$writeNum]);
        }else{
            $readNum = array_rand($connectConfig['slaveHost']);
            $sqlObj = new \mysqli($connectConfig['slaveHost'][$readNum], $connectConfig['slaveUsername'][$readNum], $connectConfig['slavePassword'][$readNum], $connectConfig['slaveDatabase'][$readNum], $connectConfig['slavePort'][$readNum]);
        }
        $sqlObj->set_charset($connectConfig['charset']);
        if($sqlObj->connect_error !== null){
            throw new \Exception('mysql connect error! 数据库连接失败');
        };
        if($type == 'write'){
            self::$writeMysql = $sqlObj;
        }else{
            self::$readMysql = $sqlObj;
        }
    }

    /**
     * 当sqlf::$config不存在数据时会从这里拉取配置
     */
    private static function getSqlConfig(){
        $configFileName = self::$configFileName;
        $config = Config::pull("$configFileName.*");
        if(isset($_SERVER['HTTP_HOST']) && isset($config[$_SERVER['HTTP_HOST']]['connections'])){
            $connectConfig = $config[$_SERVER['HTTP_HOST']]['connections']['mysql'];
        }else{
            $connectConfig =  $config['connections']['mysql'];
        }
        return $connectConfig;
    }
    
    /**
     * mysqli预处理原生语句执行模式，若无绑定则不使用预处理模式执行；更新、删除、插入
     * @param string $sqlStr sql语句
     * @param array $params 一维数组，参数排位要和sql语句中的位置一样
     * @param string $type 绑定参数类型。注意：若数据库字段为json格式并且where json对应的参数不是字符串，则该$type不能留空，要如实填写类型
     * @return int 受影响条数
     */
     public static function write($sqlStr, $params = [], $type = null){
            $sqlStr = trim($sqlStr);
            self::$sqlStr = $sqlStr.'  参数：'.json_encode($params, JSON_UNESCAPED_UNICODE);
            if(self::$sqlQuery) {
                $sqlCDUS = strtolower(substr($sqlStr, 0, 6));
                $paramsCount = count($params);
                if ($paramsCount > 0 && is_array($params)) {
                    $stmt = self::stmtPrepare($sqlStr, $params, $type);
                    if ($stmt !== false) {
                        if ($sqlCDUS == 'insert') {
                            self::$insertId = $stmt->insert_id;
                        };
                        $numRows = $stmt->affected_rows;
                        $stmt->close();
                    } else {
                        $numRows = 0;
                    }
                } else {
                    self::mysqliQuery($sqlStr);
                    $mysqli = self::mysqli($sqlStr);
                    if ($sqlCDUS == 'insert') {
                        self::$insertId = $mysqli->insert_id;
                    };
                    $numRows = $mysqli->affected_rows;
                }
                $res = $numRows;
            }else{
                $res = self::$sqlStr;
            }
            $config = self::$config;
            $thisObj = self::getThisObj();
            if($config['readSelectMaster'] && $config['deploy'] == 1 && $config['rwSeparate']){
                //多个服务器下并开启了一旦写了数据库某个表，这个表的后续操作都在 self::$writeMysql 里面进行
                $table = $thisObj->table;
                if($table !== null){
                    //识别 table 里面的表名，提取出来放到 slef::$selectMasterSqlTable 里面去
                    $tableArr = self::getTableName($table);
                    $tableArrOld = self::$selectMasterSqlTable;
                    self::$selectMasterSqlTable = array_merge($tableArr, $tableArrOld);
                }
            }
            $thisObj->table = null;
            return $res;
     }

    /**
     * 从 $this->table 中提取表名并返回
     * @return array
     */
    protected static function getTableName($table){
        $arr = explode(' ', $table);
        $tableArr = [];
        foreach ($arr as $key=>$value){
            if($tableArr == []){
                $tableArr[] = $value;
            }else{
                if($value == 'JOIN'){
                    $tableArr[] = $arr[$key+1];
                }
            }
        }
        return $tableArr;
    }

    /**
     * mysqli预处理原生语句执行模式，若无绑定则不使用预处理模式执行
     * @param string $sqlStr sql语句
     * @param array $params 一维数组，参数排位要和sql语句中的位置一样
     * @param string $type 绑定参数类型。注意：若数据库字段为json格式并且where json对应的参数不是字符串，则该$type不能留空，要如实填写类型
     * @return array 有结果返回二维数组，无则空数组
     */
    public static function read($sqlStr, $params = [], $type = null){
        self::$sqlStr = $sqlStr.'  参数：'.json_encode($params, JSON_UNESCAPED_UNICODE);
        if(self::$sqlQuery) {
            $paramsCount = count($params);
            if ($paramsCount > 0 && is_array($params)) {
                $stmt = self::stmtPrepare($sqlStr, $params, $type);
                if ($stmt !== false) {
                    $arrA = [];
                    $fieldArr = [];
                    $tableInfo = $stmt->result_metadata();
                    while ($field = $tableInfo->fetch_field()) {
                        $var = $field->name;
                        $fieldArr[] = $field->name;
                        $$var = null;
                        $arrA[] = &$$var;
                    }
                    //手册既然说 call_user_func_array 需要的是索引数组，那么这里就使用索引数组，虽然关联数组也可以
                    call_user_func_array([$stmt, 'bind_result'], $arrA);
                    $data = [];
                    while ($stmt->fetch()) {
                        $thisData = [];
                        foreach ($fieldArr as $value) {
                            $thisData[$value] = $$value;
                        }
                        $data[] = $thisData;
                    }
                    $stmt->close();
                    $return = $data;
                } else {
                    $return = [];
                }
            } else {
                //无参数绑定，不使用预处理
                $query = self::mysqliQuery($sqlStr);
                $return = self::assemblyData($query);
            }
        }else{
            $return = self::$sqlStr;
        }
        $thisObj = self::getThisObj();
        $thisObj->table = null;
        return $return;
    }

    /**
     * @param string $table 表或者 table left join table2 on(...) 之类的表关联
     * @param bool $transform 防止MYSQL关键词冲突。若$table是很复杂的语句，需要将 $transform 设置为false，若只是各表及别名等关联，则无需设置为false;若确认你的表和字段名都是正规的也可以设置为false;
     * @return $this
     */
    public static function table($table, $transform = true){
        //防止表名字段名与MYSQL的关键词重名冲突
        if($transform){
            $table = trim($table);
            if(strlen(str_replace(["\r\n", "\r", "\n", ' '], '', $table)) == strlen($table)){
                //不存在空格换行符之类
                $table = '`'.$table.'`';
            }else{
                //特殊链接符
                $joinStrArr = ['left', 'right', 'inner', 'on', 'join', 'as', 'on(', '(', ')', '=', 'and', 'or'];
                //统一空格符，方便后续操作分离及判断
                $table = strtolower(str_replace(["\r\n", "\r", "\n", ' '], ' ', $table));
                //将 括号 和 等号 俩边的空格清除，防止组合情况过多，因为()和=号在一种空格的情况下可以组合出2^5=32种案例，多个空格则无数种案例
                $tableOneArr = str_split($table);
                $tableOneArr2 = $tableOneArr;
                $tableOneArr2KeyLength = count($tableOneArr2) - 1;
                foreach($tableOneArr2 as $key=>$value){
                    if($value == '(' || $value == '=' || $value == ')'){
                        if($tableOneArr2[$key-1] == ' '){
                            unset($tableOneArr[$key-1]);
                            //清除空格的相邻空格，不管多少个都清空
                            $tableOneArr = self::tableUnset($tableOneArr2, $tableOneArr, $key-2, true);
                        }
                        if($key != $tableOneArr2KeyLength){
                            if($tableOneArr2[$key+1] == ' '){
                                if($value != ')'){
                                    unset($tableOneArr[$key+1]);
                                    $tableOneArr = self::tableUnset($tableOneArr2, $tableOneArr, $key+2, false);
                                }
                            }else{
                                if($value == ')'){
                                    // )后面必须空格隔开，否则下面分离成数组会多一种情况
                                    $tableOneArr2[$key+1] .= ' ';
                                }
                            }
                        }
                    }
                }
                //经过上面一番清理，现在已经干净无N种情况的出现了
                $table = implode('', $tableOneArr);
                $tableFieldArr = explode(' ', $table);
                //存放经过处理的$table字符串
                $newTable = '';
                foreach($tableFieldArr as $key=>$value){
                    if($value == ''){
                      //防止使用者输入过多的空格，上面清除空格仅清除 ()= 这3个符号的周边，其他地方并不清理
                      continue;  
                    };
                    if($newTable == ''){
                        //第一个肯定是原始表名，因为trim已经将首尾空格清除
                        $newTable = '`'.$value.'`';
                    }else{
                        //查看该字段是否属于join的特殊字符，若属于直接连接到新的字符串里面去  user a left join info b on(a.id=b.user_id)  user left join info on(user.id=info.user_id)
                        if(in_array($value, $joinStrArr)){
                            $newTable .= ' '.strtoupper($value);
                        }else{//不属于特殊字符，需要加反引号防冲突
                            //可能是 on(a.id=b.uid) 模式 和 a.id=b.uid 模式 和 单纯的表名或者别名
                            $dianPosition = strpos($value, '.');//不管什么模式都不可能是0，除非不存在
                            if($dianPosition){
                                $twoArr = explode('=', $value);
                                $zuoPosition = strpos($value, '(');
                                if($zuoPosition){//on(a.id=b.uid)模式  $twoArr[0] = on(a.id   $twoArr[1] = b.uid)
                                    $twoArr[0] = $twoArr[0].'`';
                                    $twoArr[0] = substr_replace($twoArr[0], '`', $zuoPosition+1, 0);//on(`a.id`
                                    $dianPosition = strpos($twoArr[0], '.');
                                    $twoArr[0] = substr_replace($twoArr[0], '`', $dianPosition, 0);//on(`a`.id`
                                    $twoArr[0] = substr_replace($twoArr[0], '`', $dianPosition+2, 0);//on(`a`.`id`
                                    $dianPosition = strpos($twoArr[1], '.');
                                    $twoArr[1] = '`'.$twoArr[1];//`b.uid)
                                    $twoArr[1] = substr_replace($twoArr[1], '`', $dianPosition+1, 0); //`b`.uid)
                                    $twoArr[1] = substr_replace($twoArr[1], '`', $dianPosition+3, 0);//`b`.`uid)
                                    if(substr($twoArr[1], strlen($twoArr[1])-1, 1) == ')'){
                                        $twoArr[1] = substr_replace($twoArr[1], '`', -1, 0);//`b`.`uid`)
                                    }else{
                                        //on(里面可能有多个条件)  ( a.id=b.uid  and  a.cid=b.cid   )
                                        //当存在多个条件时，=号右边的字符串是 b.uid  和 b.cid)  俩种情况
                                        $twoArr[1] .= '`';
                                    }
                                    //使用等号恢复连接 ... on(`a`.`id`=`b`.`uid`)
                                    $newTable .= ' '.$twoArr[0].'='.$twoArr[1];
                                }else{//a.id=b.uid 模式$twoArr[0] = a.id   $twoArr[1] = b.uid
                                    $dianPosition = strpos($twoArr[0], '.');
                                    $twoArr[0] = '`'.$twoArr[0];//`a.id
                                    $twoArr[0] = substr_replace($twoArr[0], '`', $dianPosition+1, 0); //`a`.id
                                    $twoArr[0] = substr_replace($twoArr[0], '`', $dianPosition+3, 0);//`a`.`id
                                    $twoArr[0] .= '`';//`a`.`id`
                                    $dianPosition = strpos($twoArr[1], '.');
                                    $twoArr[1] = '`'.$twoArr[1];//`b.uid
                                    $twoArr[1] = substr_replace($twoArr[1], '`', $dianPosition+1, 0); //`b`.uid
                                    $twoArr[1] = substr_replace($twoArr[1], '`', $dianPosition+3, 0);//`b`.`uid
                                    if(substr($twoArr[1], strlen($twoArr[1])-1, 1) != ')'){
                                        $twoArr[1] .= '`';//`b`.`uid`
                                    }else{
                                        //如果on(a.id=b.uid  and  a.cid=b.cid)   空格分组会得到 a.cid=b.cid)，然后就不存在 '(' 就会跑到这里处理，但是会存在 ')' ，所以判断一下 ')' 是否存在
                                        $twoArr[1] = substr_replace($twoArr[1], '`', -1, 0);
                                    }
                                    //使用等号恢复连接 ... `a`.`id`=`b`.`uid`
                                    $newTable .= ' '.$twoArr[0].'='.$twoArr[1];
                                }
                            }else{
                                $newTable .= ' `'.$value.'`';
                            }
                        }
                    }
                }
                $table = $newTable;
            }
        }
        $obj = self::getThisObj();
        $obj->table = $table;
        $obj->closeParamStr($table);
        return $obj;
    }
    
    /**
     * 删除连续空格
     * @param array $tableOneArr2
     * @param array $tableOneArr
     * @param int $key
     * @param bool $type
     * @return $tableOneArr
     */
    protected static function tableUnset($tableOneArr2, $tableOneArr, $key, $type){
        if(!empty($tableOneArr2[$key]) && $tableOneArr2[$key] == ' '){
            unset($tableOneArr[$key]);
            if($type){
                $tableOneArr = self::tableUnset($tableOneArr2, $tableOneArr, $key-1, $type);
            }else{
                $tableOneArr = self::tableUnset($tableOneArr2, $tableOneArr, $key+1, $type);
            }
        }
        return $tableOneArr;
    }


    /**
     * 部分数据恢复初始化
     */
    protected function closeParamStr(){
        $this->whereStr = '';
        $this->whereValue = [];
        $this->limitStr = '';
        $this->orderStr = '';
        $this->groupStr = '';
        $this->havingStr = '';
        self::$sqlQuery = true;
        self::$selectMasterSql = false;
        //$this->table = null;//这个分布式中可能会用到，所以在::write及::read中还原
    }

    /**
     * 获取对象自身，单例模式
     * @return $this
     */
    protected static function getThisObj(){
        if(self::$obj == null) {
            $obj = new static();
            self::$obj = $obj;
        }else{
            $obj = self::$obj;
        }
        return $obj;
    }

    public function master(){
        self::$selectMasterSql = true;
        return $this;
    }
    

    /**
     * 非预处理模式query
     * @param string $sqlStr
     * @return bool|mysqli_result
     */
    protected static function mysqliQuery($sqlStr){
        self::$sqlStr = $sqlStr;
        $mysqli = self::mysqli($sqlStr);
        $res = $mysqli->query($sqlStr);
        if($res !== false){
            return $res;
        }else{
            self::errorLog($sqlStr);
        }
    }
    
    /**
     * @param string $sqlStr
     * @param array $params
     * @param string $type
     * @return bool
     */
    protected static function stmtPrepare($sqlStr, $params, $type){
            $mysqli = self::mysqli($sqlStr);
            $stmt = $mysqli->prepare($sqlStr);
            if($stmt !== false) {
                $paramsA = [];
                $typeA = '';
                foreach ($params as $key => $value) {
                    $paramsA[] = &$params[$key];
                    $typeA .= self::paramType($value);
                }
                if ($type === null) {
                    $type = $typeA;
                };
                array_unshift($paramsA, $type);
                call_user_func_array([$stmt, 'bind_param'], $paramsA);
                $bool = $stmt->execute();
                if($bool !== false){
                    return $stmt;
                }else{
                    self::errorLog($sqlStr);
                    return false;
                }
            }else{
                self::errorLog($sqlStr);
                return false;
            }
    }
    
    /**
     * 开启事务模式，表的存储引擎为 InnoDB 方可使用事务模式
     */
    public static function startAffair(){
        self::$startAffairBool = true;
        $mysqli = self::mysqli();
        $mysqli->autocommit(false);
    }
    /**
     * 提交事务并完成事后操作
     */
    public static function affairCommit(){
        $mysqli = self::mysqli();
        $mysqli->commit();
        $mysqli->autocommit(true);
    }
    /**
     * 事务失败善后操作
     */
    public static function affairError(){
        $mysqli = self::mysqli();
        $mysqli->rollback();//回滚到开启事务模式处
        $mysqli->autocommit(true);
    }

    /**
     * @param callable $callback //Db::transaction(function(){ 执行你的代码 })
     * @param callable $errorCallback   //事务失败后执行的匿名函数，第一个参数为捕抓到的错误信息，可以在 $errorCallback 内继续调用 Db::transaction(...)事务，能嵌套使用，互不影响
     */
    public static function transaction($callback, $errorCallback = null){
        Db::startAffair();
        try {
            $callback();
            Db::affairCommit();
        } catch (\Exception $e) {
            Db::affairError();
            if($errorCallback != null) {
                $errorCallback($e);
            }
        }
    }

    /**
     * 错误日志记录
     * @param string $sqlStr
     */
    protected static function errorLog($sqlStr){
        $mysqli = self::mysqli($sqlStr);
        $Logtime = date('Y-m-d H:i:s', time());
        //进行日志记录
        $errorLog = mysqli_error($mysqli);
        $errorLogE = $errorLog;
        date_default_timezone_set('PRC');
        $errorLog2 = "【{$Logtime}】".$errorLog."\r\nURL：//".@$_SERVER['HTTP_HOST'].@$_SERVER['PHP_SELF'].'?'.@$_SERVER['QUERY_STRING'];
        $errorLog2 .= "\r\nQUERY：".$sqlStr;
        $errorLog2 .= "\r\nIP：".$_SERVER["REMOTE_ADDR"];
        if(!empty($_POST)) {
            $errorLog2 .= "\r\nPOST:" . json_encode($_POST, JSON_UNESCAPED_UNICODE);
        };
        $logDir = self::$logDir;
        if($logDir === '/../../../../log/mysql/'){
            $logDir = __DIR__.$logDir;
        }
        if(!is_dir($logDir)){
            mkdir($logDir, 0777, true);
        };
        $fileName = 'mysqlErrorLipowei'.substr($Logtime,0, 10);
        $myfile = fopen($logDir."$fileName.txt", "a");
        fwrite($myfile, $errorLog2."\r\n\r\n\r\n");
        fclose($myfile);
        if(self::$sqlDebug){
            $errorInfo = '【MYSQL错误提示】'.$errorLogE;
            $errorInfo .= PHP_EOL.'【SQL 语句】'.self::$sqlStr;
        }else{
            $errorInfo = '数据操作错误';
        }
        if(self::$startAffairBool){
            self::$startAffairBool = false;
            throw new \Exception($errorInfo);
        }else{
            //@header('HTTP/1.1 500 SQL ERROR');
            echo $errorInfo;
            exit;
        }
        

    }
    
    /**
     * 将配置信息保存到文件中
     * @param array $connectConfig
     */
    protected static function writeConfigToFile($connectConfig){
        //将配置信息写到配置文件内；先检测配置目录和文件是否存在；注意：此处路径不能随便更改，若更改上面的 Config::pull 无法使用；若一定要更改，那么上面 Config::pull 需要改成 include 引入配置文件
                $configDir = __DIR__.'/../../../../config/';
                if(!is_dir($configDir)){
                    mkdir($configDir, 0777, true);
                };
                if(empty($connectConfig['deploy'])){
                    $connectConfig['deploy'] = 0;
                }
                if(empty($connectConfig['rwSeparate'])){
                    $connectConfig['rwSeparate'] = false;
                }
                if(empty($connectConfig['readSelectMaster'])){
                    $connectConfig['readSelectMaster'] = false;
                }
                if(empty($connectConfig['slaveHost'])){
                    $connectConfig['slaveHost'] = [];
                    $connectConfig['slavePort'] = [];
                    $connectConfig['slaveDatabase'] = [];
                    $connectConfig['slaveUsername'] = [];
                    $connectConfig['slavePassword'] = [];
                }
                $connectConfigStr = [];
                foreach ($connectConfig as $key=>$value){
                    //转化为字符串写入配置文件
                    if(is_array($value)) {
                        $connectConfigStr[$key] = '[';
                        $i = 0;
                        foreach ($value as $k => $v) {
                            if($i>1){
                                $connectConfigStr[$key] .= ",'$v'";
                            }else{
                                $i = 2;
                                $connectConfigStr[$key] .= "'$v'";
                            }
                        }
                        $connectConfigStr[$key] .= ']';
                    }else{
                        if($key == 'charset'){
                            $connectConfigStr[$key] = "'$value'";
                        }else{
                            $connectConfigStr[$key] = "$value";
                        }
                    }
                }
                if($connectConfigStr['rwSeparate']){
                    $rwSeparate = 'true';
                }else{
                    $rwSeparate = 'false';
                }
                if($connectConfig['readSelectMaster']){
                    $readSelectMaster = 'true';
                }else{
                    $readSelectMaster = 'false';
                }
                if($connectConfigStr['sqlDebug']){
                    $sqlDebug = 'true';
                }else{
                    $sqlDebug = 'false';
                }
                $fileContent = "<?php
                                return [
                                    'connections'=>[
                                        'mysql' => [
                                            'host' => {$connectConfigStr['host']},
                                            'port' => {$connectConfigStr['port']},
                                            'database' => {$connectConfigStr['database']},
                                            'username' => {$connectConfigStr['username']},
                                            'password' => {$connectConfigStr['password']},
                                            'charset' => {$connectConfigStr['charset']},
                                            //是否显示SQL错误信息
                                            'sqlDebug' => $sqlDebug,
                                            //数据库部署方式:0单一服务器  1分布式（2个或以上）
                                            'deploy'  => {$connectConfigStr['deploy']},
                                            //数据库读写是否分离，分布式有效；可以不读写分离，随机使用host里面的mysql数据库，负载均衡
                                            'rwSeparate' => $rwSeparate,
                                            //在读写分离的环境下，是否开启一旦表有写操作，本次请求的后续操作涉及到该表的都使用写句柄进行操作，避免数据在读库尚未同步完成导致数据不一致
                                            'readSelectMaster' => $readSelectMaster,
                                            //从数据库，从数据库服务器只读不写；注意：只有在读写分离才将链接信息写在下面
                                            'slaveHost' => {$connectConfigStr['slaveHost']},
                                            'slavePort' => {$connectConfigStr['slavePort']},
                                            'slaveDatabase' => {$connectConfigStr['slaveHost']},
                                            'slaveUsername' => {$connectConfigStr['slaveHost']},
                                            'slavePassword' => {$connectConfigStr['slaveHost']},
                                        ]
                                    ]
                                ];";
                $configFileName = self::$configFileName;
                $configFile = $configDir."$configFileName.php";
                $myFile = fopen($configFile, "w");
                fwrite($myFile, $fileContent);
                fclose($myFile);
    }

    /**
     * 非预处理执行数据整合返回
     * @param object $res mysqli_query 执行后返回的句柄
     * @param bool $isOneRow
     * @return array
     */
    protected static function assemblyData($res, $isOneRow = false){
        if ($isOneRow == 1) {
            $row = $res->fetch_assoc();
            if(count($row)) {
                return $row;
            }else{
                return [];
            }
        } else {
            $arr = array();
            while ($row = $res->fetch_assoc()) {
                $arr[] = $row;
            }
            return $arr;
        }
    }

    /**
     * 检测普通变量的类型
     * @param $param
     * @return string s|i|d
     */
    protected static function paramType($param){
        if(is_string($param)){
            return 's';
        }elseif(is_int($param)){
            return 'i';
        }elseif(is_float($param)){
            return 'd';
        }else{
            return 's';
        }
    }

    /**
     * 防止字段为 MYSQL 关键词，识别给单个字符串加`，如：username 转成 `username`    users.username 转成  `users`.`username`
     * @param string $str
     * @param string $search
     * @return string
     */
    protected static function transform($str, $search = '.'){
        $str = trim($str);
        if(!strstr($str, '`')){
            //先检测是否存在 .号，不存在直接加``  a.nickname  name
            $position = strpos($str, $search);//因为mysql，这里不会出现0
            if($position){
                $str = '`'.$str;
                $str = substr_replace($str, '`', $position+1, 0);
                if(!strstr($str, '*')) {
                    $str = substr_replace($str, '`', $position + 3, 0);
                    $str .= '`';
                }
                return $str;
            }else{
                return "`$str`";
            }
            
        }else{
            return $str;
        }
    }

    /**
     * 当要使用反引号时，select存在别名使用
     * @param string $str
     * @param string $search
     * @return string
     */
    protected static function transform2($str, $search = '.'){
        $str = trim($str);
        $str = strtolower(str_replace(["\r\n", "\r", "\n", ' '], ' ', $str));
        $strArr = explode(' ', $str);
        $newStr = '';
        foreach($strArr as $key=>$value){
            if($value != '') {
                if ($value != 'as') {
                    $newStr .= ' ' . self::transform($value, $search);
                } else {
                    $newStr .= ' AS';
                }
            }
        }
        return $newStr;
        
    }

    //下面是非静态区域

    /**
     * 过滤表中不存在的字段
     * @param array $array
     * @param array $columns
     * @return array
     */
    protected function safe($array, $columns = []){
        $table = $this->table;
        if($columns == []){
            $query = self::mysqliQuery("show columns from $table");
            $yzarr = [];
            while ($row = $query->fetch_array()) {
                $yzarr[] = $row[0];
            }
        }else{
            $yzarr = $columns;
        }
        $newArr = [];
        foreach($array as $key=>$value){
            if(!in_array($key, $yzarr)){
                continue;
            };
            $newArr[$key] = $value;
        }
        return $newArr;
    }
    
    /**
     * and 模式；要or则请使用 $this->whereOr($where)
     * @param array|string $where 可以是一维数组和二维数组 ['name', '张三']、['name', '=', '张三']、[['name', '=', '张三'], ['age', '>=', 18]];  如果要输入字符串模式请使用安全的 Db::query
     * @param string $conditionOrValue 当$where为字符串，可以使用第二个参数作为值或者比较符号;如 where('id',  10) 得到的结果是id=10
     * @param string $value 当 $conditionOrValue 为比较符号时，则第三个参数是作为值，如where('id', '<', 10) 得到的结果是 id<10
     * @return $this
     */
    public function where($where, $conditionOrValue = null, $value = null){
        if(!empty($where)) {
            $this->whereOrAnd($where, 'AND', $conditionOrValue, $value);
        }
        return $this;
    }
    
    /**
     * or 模式
     * @param array|string $where 只能是二维数组或字符串,二维数组[ ['name', '=', '张三'], ['nickname', '=', '张三'] ] WHERE是： (`name`='张三' OR `nickname`='张三')
     * @param string $conditionOrValue 当$where是字符串时才有效，可以使用第二个参数作为值或者比较符号;如 whereOr('id',  10) 得到的结果是 OR id=10
     * @param string $value 当$where是字符串时才有效，可以使用第三个参数作为值，如 whereOr('id', '<=',  10) 得到的结果是 OR id<=10
     * @return $this
     */
    public function whereOr($where, $conditionOrValue = null, $value = null){
        if(!empty($where)) {
            $this->whereOrAnd($where, 'OR', $conditionOrValue, $value);
        }
        return $this;
    }

    /**
     * @param array|string $where
     * @param string $type
     * @param string $conditionOrValue
     * @param string $value
     */
    protected function whereOrAnd($where, $type = 'AND', $conditionOrValue = null, $value = null){
        if(is_array($where)){
            if(isset($where[0])){
                //二维数组模式
                $whereStr = '';
                foreach($where as $key=>$value){
                    if($whereStr == ''){
                        $whereStr = $this->whereArrToStr($value);
                    }else{
                        $whereStr .= " $type ".$this->whereArrToStr($value);
                    }
                }
            }else{
                //一维数组模式
                $whereStr = $this->whereArrToStr($where);
            }
            if($type == 'AND'){
                $where = $whereStr;
            }else{
                $where = '('.$whereStr.')';
            }
        }else{
            if($conditionOrValue !== null){
                if($value !== null){
                    $where = [$where, $conditionOrValue, $value];
                }else{
                    $where = [$where, $conditionOrValue];
                }
                $where = $this->whereArrToStr($where);
            }
        }
        
        if($this->whereStr == ''){
            //如果$type = OR，传入二维数组得到结果是：WHERE (`name`='张三' OR `nickname`='张三')
            $this->whereStr = 'WHERE '.$where;
        }else{
            //不管 $type 是 AND 还是 OR，这里都是使用 AND 连接；如type=OR的二维数组进入该方法，得到：...  AND  (`name`='张三' OR `nickname`='张三')
            if($conditionOrValue !== null && $type == 'OR'){
                $this->whereStr .= ' OR '.$where;
            }else{
                $this->whereStr .= ' AND '.$where;
            }
        }
    }

    /**
     * 三种模式不可混用
     * @param array $where 一维数组
     * @return string
     */
    protected function whereArrToStr($where){
        $whereStr = '';
        foreach ($where as $key=>$value){
            if(is_int($key)){
                if(count($where) == 3){
                    //[key, 符号, value] 模式
                    $where[1] = str_replace(["\r\n", "\r", "\n", ' '], ' ', strtoupper(trim($where[1])));
                    if(strstr($where[1], ' ')) {
                        $where1Arr = explode(' ', $where[1]);
                        foreach ($where1Arr as $k=>$v){
                            if($v == ''){
                                unset($where1Arr[$k]);
                            }
                        }
                        $where[1] = implode(' ', $where1Arr);
                    }
                    $where1Arr = ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'];
                    if(in_array($where[1], $where1Arr)){
                        //IN NOT IN 模式下只支持数组，不然不进行预处理
                        if($where[1] == 'IN' || $where[1] == 'NOT IN') {
                            if (is_array($where[2])) {
                                $whereStr = '(';
                                foreach ($where[2] as $k => $v) {
                                    $this->whereValue[] = $v;
                                    if ($whereStr == '(') {
                                        $whereStr .= '?';
                                    } else {
                                        $whereStr .= ',?';
                                    }
                                }
                                $whereStr .= ')';
                            } else {
                                $whereStr = $where[2];
                            }
                        }else{
                            //BETWEEN  、 NOT BETWEEN模式
                            if (is_array($where[2])) {
                                $whereStr = '? AND ?';
                                $this->whereValue[] = $where[2][0];
                                $this->whereValue[] = $where[2][1];
                            }else{
                                $whereStr = $where[2];
                            }
                        }
                        $whereStr = self::transform($where[0]) . ' ' . $where[1] . $whereStr;
                    }else{
                        $whereStr = self::transform($where[0]).' '.$where[1].' ?';
                        $this->whereValue[] = $where[2];
                    }
                }elseif(count($where) == 2){
                    // [key, value]key=value模式
                    $whereStr = self::transform($where[0]).'=?';
                    $this->whereValue[] = $where[1];
                }
            }else{
                //[key=>value]模式
                if($whereStr == '') {
                    $whereStr = self::transform($key) . '=?';
                }else{
                    $whereStr =  $whereStr . ' AND ' . self::transform($key) . '=?';
                }
                $this->whereValue[] = $value;
            }
        }

        return $whereStr;
    }
    
    /**
     * 当where nickname like %$_POST['nickname'] 时 $_POST['nickname'] 可能会存在特殊符号，例如 ? % _ 会导致出现不是需要的查询结果，所以需要使用 likeConcat；
     * @param string $field 需要模糊查询的字段名
     * @param string $concat 例如： $concat = "'%',?,'%'"  需要使用 ? 来做占位符，然后将?对应的数据放到第3个参数里面去
     * @param array $concatValue 例如：$concatValue = '中文搜';
     * @return $this
     */
    public function likeConcat($field, $concat, $concatValue = []){//LIKE CONCAT('%',?,'%') ")
        $field = self::transform(trim($field));
        $sqlStr = "$field LIKE CONCAT($concat)";
        if($this->whereStr == ''){
            $this->whereStr = "WHERE $sqlStr";
        }else{
            $this->whereStr .= " AND $sqlStr";
        }
        if(is_array($concatValue)){
            foreach($concatValue as $key=>$value){
                $this->whereValue[] = $value;
            }
        }else{
            $this->whereValue[] = $concatValue;
        }
        return $this;
    }


    /**
     * @param string $noParam 不真正执行SQL操作，例如想看本类合成的sql语句  echo DB::table('users a left join email b on(a.id=b.user_id)')->where('a.id', 6)->noQuery()->select();
     * @return $this
     */
    public function noQuery($noParam = '无需传入参数，设该参数是为了IDE提示 noQuery 的作用'){
        self::$sqlQuery = false;
        return $this;
    }


    /**
     * 插入 Db::table(...)->insert(...)
     * @param array $array 一维关联数组，key为字段，value为值
     * @param array $columns 一维数组，限制只能插入某些字段，空数组即为不限制
     * @param bool $filter 是否自动排除不存在数据表内的字段（过滤非法字段，懒人操作防注入）
     * @param null $type 各个字段在数据库内的数据类型
     * @return int 插入条数。若要获取自增ID Db::$insertId 
     */
    public function insert($array, $columns = [], $filter = false, $type = null){
        $table = $this->table;
        if(empty($columns)){
            if($filter){
                $array = $this->safe($array);
            }
        }else{
            if(!is_array($columns)){
                $columns = explode(',',$columns);
            }
            $array = $this->safe($array, $columns);
        }

        $keyStr = '';
        $valueaStr = '';
        $i = 1;
        $valueArr = [];
        foreach($array as $key=>$value){
            if($i==1){
                $keyStr = "`$key`";
                $valueaStr = '?';
                $i = 2;
            }else{
                $keyStr .= ",`$key`";
                $valueaStr .= ',?';
            }
            $valueArr[] = $value;
        }
        $sqlStr = "INSERT INTO $table($keyStr) VALUES($valueaStr)";
        return self::write($sqlStr, $valueArr, $type);
    }
    
    /**
     * 删除  Db::table(...)->where(...)->delete()
     * @param bool $all 若要不带条件删除整张表，该参数需要设置为true
     * @param string $type 各参数的类型
     * @return int 删除行数
     */
    public function delete($all = false, $type = null){
        $where = $this->whereStr;
        if($where == '' && $all === false){
            throw new \Exception('删除数据表内容需要指定条件，如果要强制执行，第1个参数传 true');
        }else{
            $table = $this->table;
            $valueArr = $this->whereValue;
            $sqlStr = "DELETE FROM $table $where";
            return self::write($sqlStr, $valueArr, $type);
        }
    }

    /**
     * 更新  Db::table(...)->where(...)->update(...)
     * @param array $array 一维数组，key为更新字段，value为更新的值
     * @param array $columns 只能更新的字段，即是更新白名单
     * @param bool $filter true的条件下过滤不存在表中的字段；仅在 ->update($_POST,...)  ->update($_GET,...) 等数组中的key不可信的情况下使用，如果是自己定义的 key 则不需要开启 $filter = true
     * @param bool $all 若要不带条件更新整张表，该参数需要设置为true
     * @param string $type 各参数的类型
     * @return int 更新行数
     */
    public function update($array, $columns = [], $filter = false, $all = false, $type = null){
        if(is_array($array)){
            $where = $this->whereStr;
            $table = $this->table;
            if($where == '' && $all === false){
                throw new \Exception('where 不能为空，如果要更新所有，请将在第4个参数传 true');
            }else{
                if(empty($columns)){
                    if($filter){
                        $array = $this->safe($array);
                    }
                }else{
                    if(!is_array($columns)){
                        $columns = explode(',',$columns);
                    }
                    $array = $this->safe($array, $columns);
                }
                $whereValue = $this->whereValue;
                $where = $this->whereStr;

                $updateStr = '';
                $updateValue = [];
                foreach($array as $key=>$value){
                    $updateValue[] = $value;
                    if($updateStr === ''){
                        $updateStr = self::transform($key).'=?';
                    }else{
                        $updateStr .= ','.self::transform($key).'=?';
                    }
                }
                foreach($whereValue as $key=>$value){
                    $updateValue[] = $value;
                }
                $sqlStr = "UPDATE $table SET $updateStr $where";
                return self::write($sqlStr, $updateValue, $type);
            }
        }else{
            throw new \Exception('update()第一个参数必须是一维数组');
        }
    }
    
    /**
     * @param string $field 查询的字段，当为空时查询所有，即为 *
     * @param bool $more 默认查询多条，返回二维数组；若设置为 false 则只查询一条，返回一维数组
     * @return array
     */
    public function select($field = '*', $more = true){
        $where = $this->whereStr;
        $whereValue = $this->whereValue;
        $table = $this->table;
        $orderBy = $this->orderStr;
        $groupBy = $this->groupStr;
        $hvaing = $this->havingStr;
        $limit = $this->limitStr;

        if($field != '*' && !strstr($field, '(')){
            //加防冲突符号
            $fieldArr = explode(',', $field);
            $newFieldStr = '';
            foreach($fieldArr as $key=>$value){
                $value = trim($value);
                if(strlen(str_replace(["\r\n", "\r", "\n", ' '], '', $value)) == strlen($value)){
                    //加防冲突符
                    $value = self::transform($value);
                }else{
                    $value = self::transform2($value);
                }
                if(empty($newFieldStr)){
                    $newFieldStr = $value;
                }else{
                    $newFieldStr .= ','.$value;
                }
            }  
            $field = $newFieldStr;
        };
        if(!$more){
            if(empty($limit)){
                $limit = 'LIMIT 0,1';
                $moreOne = true;
            }
        }
        $sqlStr = "SELECT $field FROM $table $where $groupBy $orderBy $hvaing $limit";
        $res = self::read($sqlStr, $whereValue);
        if(!empty($moreOne)){
            if(!empty($res[0])){
                $res = $res[0];
            }
        }
        return $res;
    }

    public function find($field = '*'){
        return $this->select($field, false);
    }

    /**
     * @param string $field 需要查询的字段
     * @return mixed|false 如果存在所查询的值则返回值，否则返回false
     */
    public function value($field){
        $res = $this->select($field, false);
        if(isset($res[$field])){
            return $res[$field];
        }else{
            return false;
        }
    }
    
    /**
     * @return int
     */
    public function count(){
        $res = $this->select('count(*) as countNum');
        //如果noQuery为true，那么返回的是 sql 的拼接语句
        if(isset($res[0]['countNum'])){
            return $res[0]['countNum'];
        }else{
            return $res;
        } 
    }
    
    /**
     * @param string field
     * @return int
     */
    public function sum($field){
         return $this->fuc($field, 'sum');
    }
    
    /**
     * @param string field
     * @return int
     */
    public function avg($field){
        return $this->fuc($field, 'avg');
    }
    
    /**
     * @param string field
     * @return int
     */
    public function max($field){
        return $this->fuc($field, 'max');
    }
    
    /**
     * @param string field
     * @return int
     */
    public function min($field){
        return $this->fuc($field, 'min');
    }
    
    /**
     * @param string $field
     * @param string $fuc
     * @return int 
     */
    protected function fuc($field, $fuc){
        $field = self::transform($field);
        $res = $this->select("$fuc($field) as num");
        if(isset($res[0]['num'])){
            return $res[0]['num'];
        }else{
            return $res;
        }
    }

    /**
     * @param string $field
     * @param int $num
     * @return int 返回0表示更新失败，反之返回执行更新的行数
     */
    public function setInc($field, $num = 1, $all = false){
        return $this->fieldIncOrDec($field, $num, '+', $all);
    }

    /**
     * @param string $field
     * @param int $num
     * @return int 返回0表示更新失败，反之返回执行更新的行数
     */
    public function setDec($field, $num = 1, $all = false){
        return $this->fieldIncOrDec($field, $num, '-', $all);
    }

    /**
     * @param string $field
     * @param int $num
     * @param string $type
     * @return int
     */
    protected function fieldIncOrDec($field, $num, $type, $all){
        $num = (int)$num;
        $field = self::transform($field);
        $table = $this->table;
        $where = $this->whereStr;
        if($where == '' && $all == false){
            echo '更新数值需要条件，请设置 ->where() ;若要强制更新整个表，请传入第3个参数为 true';
            exit;
        }else{
            $sqlStr = "UPDATE $table SET $field=$field{$type}$num $where";
            $thisObj = self::getThisObj();
            return self::write($sqlStr, $thisObj->whereValue);
        }
    }
    

    /**
     * @param int $start 从第几位开始取；如第一位即填写0
     * @param int $length 共取多少位
     * @return $this
     */
    public function limit($start = 1, $length = null){
        if($length == null){
            $this->limitStr = "LIMIT $start";
        }else{
            $this->limitStr = "LIMIT $start,$length";
        }
        return $this;
    }
    
    /**
     * @param string|array $field string例子：->order('id', 'DESC')  array例子：->order(['time'=>'DESC', 'id'=>'DESC'])  简便模式：->order(['time', 'id'], 'DESC') 如果第2个参数不填写默认为ASC
     * @param string:default:asc $rank 如果设置为 false ,则可以使用自定义排序：$field=FIELD(fieldName, 'li', 'po', 'wei') ASC
     * @return $this
     */
    public function order($field, $rank = 'ASC'){
        if(is_array($field)){
            $orderStr = 'ORDER BY ';
            $i = 1;
            foreach ($field as $key=>$value){
                if( is_int($key) ){
                    $value = self::transform($value);
                    if($i == 1){
                        $orderStr .= "$value $rank ";
                        $i = 2;
                    }else{
                        $orderStr .= ",$value $rank ";
                    }
                }else{
                    $key = self::transform($key);
                    if($i == 1){
                        $orderStr .= "$key $value ";
                        $i = 2;
                    }else{
                        $orderStr .= ",$key $value ";
                    }
                }
            }
            $this->orderStr = $orderStr;
        }else{
            if($rank !== false){
                $field = self::transform($field);
                $this->orderStr = "ORDER BY $field $rank";
            }else{
                //如果是字符串模式，若存在mysql的关键词，需要加上``防止冲突
                $this->orderStr = "ORDER BY $field";
            }
        }
        return $this;
    }

    /**
     * @param string|array $group
     * @return $this
     */
    public function group($group){
        if(is_array($group)){
            foreach ($group as $key=>$value){
                $group[$key] = self::transform($value);
            }
            $group = implode(',', $group);
        }else{
            $group = self::transform($group);
        }
        $this->groupStr = "GROUP BY $group";
        return $this;
    }
    
    /**
     * @param string $hvaing
     * @return $this
     */
    public function hvaing($hvaing){
        $this->hvaingStr = "HAVING $hvaing";
        return $this;
    }
}