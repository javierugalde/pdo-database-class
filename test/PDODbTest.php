<?php

/**
 * PDODb class testing
 *
 * @author    Vasiliy A. Ulin <ulin.vasiliy@gmail.com>
 * @license http://www.gnu.org/licenses/lgpl.txt LGPLv3
 */
class PDODbTest extends PHPUnit_Framework_TestCase
{
    /**
     * Test data
     * @var array
     */
    private $data = [];

    /**
     * PDODbHelper instance
     * @var PDODbHelper
     */
    private $helper = null;

    public function __construct()
    {
        $PDODb        = new PDODb('mysql');
        $this->data   = include('include/PDODbData.php');
        require_once('include/PDODbHelper.php');
        $this->helper = new PDODbHelper();
        parent::__construct();
    }

    public function testInitByArray()
    {
        $PDODb = new PDODb($this->data['params']);
        $this->assertInstanceOf('PDO', $PDODb->pdo());
    }

    public function testInitByObject()
    {
        $object = new PDO($this->data['params']['type'].':'
            .'host='.$this->data['params']['host'].';'
            .'dbname='.$this->data['params']['dbname'].';'
            .'port='.$this->data['params']['port'].';'
            .'charset='.$this->data['params']['charset'], $this->data['params']['username'], $this->data['params']['password']);
        $PDODb  = new PDODb($object);
        $this->assertInstanceOf('PDO', $PDODb->pdo());
    }

    public function testInitByParams()
    {
        $PDODb = new PDODb($this->data['params']['type'], $this->data['params']['host'], $this->data['params']['username'], $this->data['params']['password'],
            $this->data['params']['dbname'], $this->data['params']['port'], $this->data['params']['charset']);
        $this->assertInstanceOf('PDO', $PDODb->pdo());
    }

    public function testGetInstance()
    {
        $PDODb = PDODb::getInstance();
        $this->assertInstanceOf('PDODb', $PDODb);
        $this->assertInstanceOf('PDO', $PDODb->pdo());
    }

    public function testTableCreationAndExistance()
    {
        $PDODb = PDODb::getInstance();
        foreach ($this->data['tables'] as $name => $fields) {
            $this->helper->createTable($this->data['prefix'].$name, $fields);
            $this->assertTrue($PDODb->tableExists($this->data['prefix'].$name));
        }
    }

    public function testInsertAndPrefix()
    {
        $PDODb = PDODb::getInstance();
        $PDODb->setPrefix($this->data['prefix']);
        foreach ($this->data['data'] as $tableName => $tableData) {
            foreach ($tableData as $row) {
                $lastInsertId = $PDODb->insert($tableName, $row);
                $this->assertInternalType('int', $lastInsertId);
            }
        }

        // bad insert test
        $badUser = ['login' => null,
            'customerId' => 10,
            'firstName' => 'John',
            'lastName' => 'Doe',
            'password' => 'test',
            'createdAt' => $PDODb->now(),
            'updatedAt' => $PDODb->now(),
            'expires' => $PDODb->now('+1Y'),
            'loginCount' => $PDODb->inc()
        ];

        $lastInsertId = $PDODb->insert('users', $badUser);
        $this->assertFalse($lastInsertId);
    }

    public function testInsertWithoutAutoincrement()
    {
        $PDODb = PDODb::getInstance();
        $query = "DROP TABLE {$this->data['prefix']}test";
        $PDODb->rawQuery($query);
        $query = "CREATE TABLE {$this->data['prefix']}test (id INT(10), name VARCHAR(10));";
        $PDODb->rawQuery($query);
        $id    = $PDODb->insert("test", ["id" => 2, "name" => "testname"]);
        $this->assertEquals(2, $id);
    }

    public function testInsertOnDuplicateUpdateAndGetOne()
    {
        $PDODb         = PDODb::getInstance();
        $user          = ['login' => 'user3', 'updatedAt' => $PDODb->now('+1s')];
        $updateColumns = ["updatedAt"];
        $PDODb->onDuplicate($updateColumns, "id");
        $result        = $PDODb->insert("users", $user);
        $this->assertEquals(3, $result);

        $updatedUser = $PDODb->where('id', 3)->getOne('users');
        $this->assertInternalType('array', $updatedUser);
        $this->assertNotEquals($updatedUser['createdAt'], $updatedUser['updatedAt']);
    }

    public function testGetTotalCountAndOrderBy()
    {
        $PDODb       = PDODb::getInstance();
        $result      = $PDODb->withTotalCount()->orderBy('login', 'DESC')->get('users');
        $this->assertInternalType('array', $result[0]);
        $this->assertEquals('user3', $result[0]['login']);
        $this->assertEquals(3, $PDODb->totalCount);
    }

    public function testUpdateAndBooleanWhereAndInc()
    {
        $PDODb = PDODb::getInstance();

        $PDODb->withTotalCount()->where("active", true);
        $users = $PDODb->get("users");
        $this->assertEquals(1, $PDODb->totalCount);

        $PDODb->where("active", false)->update("users", ["active" => $PDODb->not()]);
        $this->assertEquals(2, $PDODb->getRowCount());

        $PDODb->withTotalCount()->where("active", true);
        $users = $PDODb->get("users");
        $this->assertEquals(3, $PDODb->totalCount);

        $data = [
            'expires' => $PDODb->now("+5M", "expires"),
            'loginCount' => $PDODb->inc()
        ];
        
        $result = $PDODb->where("id", 1)->update("users", $data);        
        $this->assertTrue($result);
        $this->assertEquals(1, $PDODb->getRowCount());

        $result = $PDODb->where('id', 1)->where('expires > NOW()')->getOne('users', ['loginCount']);
        $this->assertInternalType('array', $result);
        $this->assertEquals(2,  $result['loginCount']);
    }

    public function testLimitOnGet()
    {
        $PDODb  = PDODb::getInstance();
        $result = $PDODb->get('users', 2);
        $count  = 0;
        foreach ($result as $row) {
            $this->assertInternalType('array', $row);
            $count++;
        }
        $this->assertEquals(2, $count);
    }

    public function testWhereConditions()
    {
        $PDODb = PDODb::getInstance();
        $PDODb->withTotalCount()->where("id", [1, 2, 3], 'IN')->get("users");
        $this->assertEquals(3, $PDODb->totalCount);

        $PDODb->withTotalCount()->where("id", [2, 3], 'between')->get("users");
        $this->assertEquals(2, $PDODb->totalCount);

        $PDODb->withTotalCount()->where("id", 3)->orWhere("customerId", 10)->get("users");
        $this->assertEquals(3, $PDODb->totalCount);

        $PDODb->withTotalCount()->where("id = 1 or id = 2")->get("users");
        $this->assertEquals(2, $PDODb->totalCount);

        $PDODb->withTotalCount()->where("lastName", null, '<=>')->get("users");
        $this->assertEquals(1, $PDODb->totalCount);

        $PDODb->withTotalCount()->where("id = ? or id = ?", [1, 2])->get("users");
        $this->assertEquals(2, $PDODb->totalCount);
    }

    public function testJoin()
    {
        $PDODb = PDODb::getInstance();
        $PDODb->withTotalCount()
            ->join("users u", "p.userId=u.id", "LEFT")
            ->where("u.login", 'user2')
            ->orderBy("CONCAT(u.login, u.firstName)")
            ->get("products p", null, "u.login, p.productName");
        $this->assertEquals(2, $PDODb->totalCount);
    }

    public function testSubQueryCopyAndGetValue()
    {
        $PDODb = PDODb::getInstance();

        $subQuery = $PDODb->subQuery();
        $subQuery->where('active', 1);
        $subQuery->get('users', null, 'id');
        $copiedDb = $PDODb->copy();
        $result   = $copiedDb->getValue('users', "COUNT(id)");
        $this->assertEquals(3, $result);

        $subQuery = $PDODb->subQuery("u");
        $subQuery->where("active", 1);
        $subQuery->get("users");
        $PDODb->join($subQuery, "p.userId=u.id", "LEFT");
        $result   = $PDODb->get("products p", null, "u.login, p.productName");

        $count = 0;
        foreach ($result as $row) {
            $this->assertInternalType('array', $row);
            $count++;
        }
        $this->assertEquals(5, $count);
    }

    public function testDelete()
    {
        $PDODb = PDODb::getInstance();
        $PDODb->where('id', 1)->delete("users");

        $PDODb->withTotalCount()->get('users');
        $this->assertEquals(2, $PDODb->totalCount);
    }

    public function testReturnType()
    {
        $PDODb = PDODb::getInstance();
        $result = $PDODb->setReturnType(PDO::FETCH_OBJ)->where('id', 2)->getOne('users');
        $this->assertInternalType('object', $result);
    }
}