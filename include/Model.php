<?php

namespace Labels;

use \Silex\Application;
use \Symfony\Component\HttpFoundation\Request;
use \Symfony\Component\HttpFoundation\Response;
use \Doctrine\DBAL\Schema\Schema;

class Model {
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->prefix = $this->app['config']->get('general/database/prefix', "bolt_") . 'labels_';
        $this->cache = array();
    }

    public function getTablesSchema(Schema $schema) {
        $tables = array();

        $table = $schema->createTable($this->prefix . 'labels');
        $table->addColumn("id", "integer", array('autoincrement' => true));
        $table->setPrimaryKey(array("id"));
        $table->addColumn("namespace", "string", array("length" => 255));
        $table->addColumn("label", "string", array("length" => 255));
        $table->addUniqueIndex(array('namespace', 'label'));
        $tables[] = $table;

        $table = $schema->createTable($this->prefix . 'translations');
        $table->addColumn("id", "integer", array('autoincrement' => true));
        $table->setPrimaryKey(array("id"));
        $table->addColumn("label_id", "integer", array());
        $table->addColumn("language", "string", array("length" => 2));
        $table->addColumn("translation", "string", array("length" => 255));
        $table->addUniqueIndex(array('label_id', 'language'));
        $tables[] = $table;

        return $tables;
    }

    private function escapeCacheKeyElement($elem) {
        return str_replace(
                array(':', '\\'),
                array('\\:', '\\\\'),
                $elem);
    }

    private function makeCacheKey($namespace, $label, $language) {
        return md5(implode(':', array(
            $this->escapeCacheKeyElement($namespace),
            $this->escapeCacheKeyElement($label),
            $language)));
    }

    private function _getTranslationInternal($namespace, $label, $language)
    {
        $db = $this->app['db'];
        $query = 
            "SELECT t.id, t.translation, l.id as label_id " .
            "    FROM {$this->prefix}labels l " .
            "    LEFT JOIN {$this->prefix}translations t " .
            "        ON t.label_id = l.id AND t.language = :language " .
            "    WHERE l.namespace = :namespace AND l.label = :label " .
            "    LIMIT 1 ";
        $params = array(
            'namespace' => $namespace,
            'label' => $label,
            'language' => $language
            );
        $row = $db->fetchAssoc($query, $params);
        if (!is_array($row) || !isset($row['label_id'])) {
            // Label record does not exist
            $labelRow = array(
                'namespace' => $namespace,
                'label' => $label);
            $db->insert($this->prefix . 'labels', $labelRow);
            $label_id = $db->lastInsertId();
            var_dump($label_id);
            return array('translation' => $label, 'label_id' => $label_id);
        }
        else {
            return $row;
        }
    }

    public function getTranslation($namespace, $label, $language) {
        $cacheKey = $this->makeCacheKey($namespace, $label, $language);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        $row = $this->_getTranslationInternal($namespace, $label, $language);
        if (is_array($row) && isset($row['translation'])) {
            $result = $row['translation'];
        }
        else {
            $result = $label;
        }
        $this->cache[$cacheKey] = $result;
        return $result;
    }

    public function saveTranslation($namespace, $label, $language, $translation) {
        $cacheKey = $this->makeCacheKey($namespace, $label, $language);
        $this->cache[$cacheKey] = $translation;
        $db = $this->app['db'];

        $labelRow = $this->_getTranslationInternal($namespace, $label, $language);
        $labelRow['translation'] = $translation;

        if (is_null($labelRow['id'])) {
            unset($labelRow['id']);
            $db->insert($this->prefix . 'labels', $labelRow);
        }
        else {
            $id = $labelRow['id'];
            unset($labelRow['id']);
            $db->update($this->prefix . 'labels', $labelRow, $id);
        }
    }

    public function getTranslatableItems($sourceLanguage, $destLanguage, $untranslatedOnly = false, $pageIndex = 0, $pageSize = 10) {
        $db = $this->app['db'];
        $query =
            "SELECT l.id as label_id, l.namespace, l.label, st.translation as source_translation, dt.translation as translation " .
            "    FROM {$this->prefix}labels l " .
            "    LEFT JOIN {$this->prefix}translations st ON st.label_id = l.id AND st.language = :source_language " .
            "    LEFT JOIN {$this->prefix}translations dt ON dt.label_id = l.id AND dt.language = :dest_language " .
            "    ORDER BY l.namespace, l.label ";
        if ($untranslatedOnly) {
            $query .= " WHERE dt.id IS NULL ";
        }
        $query .= " LIMIT " . intval($pageSize * $pageIndex) . ", " . intval($pageSize) . " ";
        $params = array(
            'source_language' => $sourceLanguage,
            'dest_language' => $destLanguage,
            'offset' => $pageSize * $pageIndex,
            'page_size' => $pageSize);
        return $db->fetchAll($query, $params);
    }

};
