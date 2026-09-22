<?php

/**
 * Minimal stand-in for PrestaShop's Category class, covering only what
 * GCategories::getPath() reads: id/id_category, id_parent, active, name.
 *
 * Real PrestaShop loads this from the database by id; this stub reads
 * from an in-memory fixture array a test populates via self::$fixtures
 * before instantiating GCategories, so getPath()'s recursive parent
 * traversal can be exercised without a database.
 */
class Category
{
    /** @var array<int, array{id_parent: int, active: int, name: string}> */
    public static $fixtures = [];

    public $id = 0;
    public $id_category = 0;
    public $id_parent = 0;
    public $active = 0;
    public $name = '';

    public function __construct($id_category = null, $id_lang = null, $id_shop = null)
    {
        if ($id_category === null || !isset(self::$fixtures[$id_category])) {
            return;
        }

        $data = self::$fixtures[$id_category];
        $this->id = (int) $id_category;
        $this->id_category = (int) $id_category;
        $this->id_parent = (int) $data['id_parent'];
        $this->active = (int) $data['active'];
        $this->name = $data['name'];
    }

    public static function getRootCategory($id_lang = null, $shop = null)
    {
        $category = new self();
        $category->id = 1;
        $category->id_category = 1;

        return $category;
    }
}
