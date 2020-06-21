<?php

use yii\db\Migration;

/**
 * Class m200621_130053_add_sort_column__to_image_table
 */
class m200621_130053_add_sort_column__to_image_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%image}}', 'sort', $this->integer());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%image}}', 'sort');
    }

}
