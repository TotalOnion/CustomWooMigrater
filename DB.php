<?php

class DB {

    const ENVIRONMENT_STAGE = 'stage';
    const ENVIRONMENT_LIVE = 'live';

    const USER = 'root';
    const PASS = 'root';

    private $stageDb;
    private $liveDb;

    public function __construct()
    {
        try {
          $this->stageDb = new PDO("mysql:host=localhost;dbname=local", DB::USER, DB::PASS);
          $this->stageDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

          $this->liveDb = new PDO("mysql:host=localhost;dbname=live", DB::USER, DB::PASS);
          $this->liveDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        } catch(PDOException $e) {
          echo "Connections failed: " . $e->getMessage();
        }
    }

    private function getEnv(string $environment)
    {
        switch ($environment) {
            case DB::ENVIRONMENT_STAGE:
                return $this->stageDb;
                break;

            case DB::ENVIRONMENT_LIVE:
                return $this->liveDb;
                break;
        }

        throw new \Exception('Unknown environment');
    }

    public function getValue(
        string $environment,
        string $query,
        array $parameters = []
    ) {
        $db = $this->getEnv($environment);
        $query = $db->prepare($query);
        $query->execute($parameters);
        return $query->fetchColumn();
    }

    public function getColumn(
        string $environment,
        string $query,
        array $parameters = []
    ) {
        $db = $this->getEnv($environment);
        $query = $db->prepare($query);
        $query->execute($parameters);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getRow(
        string $environment,
        string $query,
        array $parameters = []
    ) {
        $db = $this->getEnv($environment);
        $query = $db->prepare($query);
        $query->execute($parameters);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function getRows(
        string $environment,
        string $query,
        array $parameters = []
    ) {
        $db = $this->getEnv($environment);
        $query = $db->prepare($query);
        $query->execute($parameters);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(
        string $environment,
        string $table,
        string $idColumnName,
        array $data
    ): ?array {
        $db = $this->getEnv($environment);
        $keysString = implode(',',array_keys($data));
        $dataString = ':'.implode(',:', array_keys($data));
        $query = $db->prepare("INSERT INTO $table ($keysString) VALUES ($dataString);");
        $query->execute($data);
        
        $insertedId = $db->lastInsertId();
        if ($insertedId) {
            return $this->getRow(
                $environment,
                "SELECT * FROM $table WHERE $idColumnName=? LIMIT 1;",
                [$insertedId]
            );
        } else {
            return null;
        }
    }

    public function updateOne(
        string $environment,
        string $table,
        string $idColumnName,
        int $id,
        array $data
    ): ?array {
        $db = $this->getEnv($environment);
        $fields = [];

        foreach ($data as $fieldName => $fieldValue) {
            $fields[] = "$fieldName = :$fieldName";
        }

        $fields = implode(',', $fields);

        $query = $db->prepare("UPDATE $table SET $fields WHERE $idColumnName = :idColumnValue LIMIT 1;");
        $data['idColumnValue'] = $id;
        $query->execute($data);

        return $this->getRow(
            $environment,
            "SELECT * FROM $table WHERE $idColumnName = :idColumnValue LIMIT 1;",
            [ 'idColumnValue' => $id ]
        );
    }
}
