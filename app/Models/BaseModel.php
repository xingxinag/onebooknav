<?php
/**
 * OneBookNav - 基础模型类
 *
 * 提供基本的数据库操作方法
 */

abstract class BaseModel
{
    protected $db;
    protected $table;
    protected $primaryKey = 'id';
    protected $fillable = [];
    protected $guarded = ['id', 'created_at', 'updated_at'];
    protected $timestamps = true;
    protected $casts = [];

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * 查找单个记录
     */
    public function find($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $this->castAttributes($result) : null;
    }

    /**
     * 查找单个记录或抛出异常
     */
    public function findOrFail($id)
    {
        $result = $this->find($id);
        if (!$result) {
            throw new Exception("Record not found with ID: {$id}");
        }
        return $result;
    }

    /**
     * 根据条件查找第一个记录
     */
    public function where($column, $operator = '=', $value = null)
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$column} {$operator} ? LIMIT 1");
        $stmt->execute([$value]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $this->castAttributes($result) : null;
    }

    /**
     * 获取所有记录
     */
    public function all($orderBy = null, $direction = 'ASC')
    {
        $sql = "SELECT * FROM {$this->table}";

        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }

        $stmt = $this->db->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'castAttributes'], $results);
    }

    /**
     * 分页查询
     */
    public function paginate($page = 1, $perPage = 20, $conditions = [], $orderBy = null, $direction = 'ASC')
    {
        $offset = ($page - 1) * $perPage;

        // 构建查询条件
        $whereClause = '';
        $params = [];

        if (!empty($conditions)) {
            $whereConditions = [];
            foreach ($conditions as $column => $value) {
                if (is_array($value)) {
                    $placeholders = str_repeat('?,', count($value) - 1) . '?';
                    $whereConditions[] = "{$column} IN ({$placeholders})";
                    $params = array_merge($params, $value);
                } else {
                    $whereConditions[] = "{$column} = ?";
                    $params[] = $value;
                }
            }
            $whereClause = ' WHERE ' . implode(' AND ', $whereConditions);
        }

        // 计算总数
        $countSql = "SELECT COUNT(*) FROM {$this->table}{$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // 查询数据
        $sql = "SELECT * FROM {$this->table}{$whereClause}";

        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }

        $sql .= " LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map([$this, 'castAttributes'], $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    /**
     * 创建记录
     */
    public function create($data)
    {
        $data = $this->filterFillable($data);

        if ($this->timestamps) {
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $columns = array_keys($data);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';

        $sql = "INSERT INTO {$this->table} (" . implode(',', $columns) . ") VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));

        $id = $this->db->lastInsertId();
        return $this->find($id);
    }

    /**
     * 更新记录
     */
    public function update($id, $data)
    {
        $data = $this->filterFillable($data);

        if ($this->timestamps) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $columns = array_keys($data);
        $setClause = implode(' = ?, ', $columns) . ' = ?';

        $sql = "UPDATE {$this->table} SET {$setClause} WHERE {$this->primaryKey} = ?";
        $params = array_merge(array_values($data), [$id]);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->find($id);
    }

    /**
     * 删除记录
     */
    public function delete($id)
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?");
        return $stmt->execute([$id]);
    }

    /**
     * 批量删除
     */
    public function deleteWhere($conditions)
    {
        $whereConditions = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            $whereConditions[] = "{$column} = ?";
            $params[] = $value;
        }

        $whereClause = implode(' AND ', $whereConditions);
        $sql = "DELETE FROM {$this->table} WHERE {$whereClause}";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * 计数
     */
    public function count($conditions = [])
    {
        $whereClause = '';
        $params = [];

        if (!empty($conditions)) {
            $whereConditions = [];
            foreach ($conditions as $column => $value) {
                $whereConditions[] = "{$column} = ?";
                $params[] = $value;
            }
            $whereClause = ' WHERE ' . implode(' AND ', $whereConditions);
        }

        $sql = "SELECT COUNT(*) FROM {$this->table}{$whereClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /**
     * 检查记录是否存在
     */
    public function exists($conditions)
    {
        return $this->count($conditions) > 0;
    }

    /**
     * 搜索
     */
    public function search($keyword, $columns = [], $page = 1, $perPage = 20)
    {
        if (empty($columns)) {
            throw new Exception("Search columns must be specified");
        }

        $offset = ($page - 1) * $perPage;

        // 构建搜索条件
        $searchConditions = [];
        $params = [];

        foreach ($columns as $column) {
            $searchConditions[] = "{$column} LIKE ?";
            $params[] = "%{$keyword}%";
        }

        $whereClause = ' WHERE ' . implode(' OR ', $searchConditions);

        // 计算总数
        $countSql = "SELECT COUNT(*) FROM {$this->table}{$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // 查询数据
        $sql = "SELECT * FROM {$this->table}{$whereClause} LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map([$this, 'castAttributes'], $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'keyword' => $keyword
        ];
    }

    /**
     * 过滤可填充字段
     */
    protected function filterFillable($data)
    {
        if (!empty($this->fillable)) {
            return array_intersect_key($data, array_flip($this->fillable));
        }

        if (!empty($this->guarded)) {
            return array_diff_key($data, array_flip($this->guarded));
        }

        return $data;
    }

    /**
     * 类型转换
     */
    protected function castAttributes($attributes)
    {
        foreach ($this->casts as $key => $type) {
            if (isset($attributes[$key])) {
                switch ($type) {
                    case 'int':
                    case 'integer':
                        $attributes[$key] = (int) $attributes[$key];
                        break;
                    case 'bool':
                    case 'boolean':
                        $attributes[$key] = (bool) $attributes[$key];
                        break;
                    case 'float':
                        $attributes[$key] = (float) $attributes[$key];
                        break;
                    case 'string':
                        $attributes[$key] = (string) $attributes[$key];
                        break;
                    case 'array':
                    case 'json':
                        $attributes[$key] = json_decode($attributes[$key], true);
                        break;
                    case 'datetime':
                        $attributes[$key] = $attributes[$key] ? new DateTime($attributes[$key]) : null;
                        break;
                }
            }
        }

        return $attributes;
    }

    /**
     * 开始事务
     */
    public function beginTransaction()
    {
        return $this->db->beginTransaction();
    }

    /**
     * 提交事务
     */
    public function commit()
    {
        return $this->db->commit();
    }

    /**
     * 回滚事务
     */
    public function rollback()
    {
        return $this->db->rollback();
    }

    /**
     * 执行原生 SQL 查询
     */
    public function query($sql, $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 执行原生 SQL 语句
     */
    public function execute($sql, $params = [])
    {
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}