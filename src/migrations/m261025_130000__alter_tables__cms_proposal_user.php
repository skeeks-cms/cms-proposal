<?php

use yii\db\Migration;

/**
 * A client person (cms_user, not an employee) as the fourth kind of the
 * client of a proposal, beside a lead, a company and a deal.
 */
class m261025_130000__alter_tables__cms_proposal_user extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_proposal}}', 'cms_user_id', $this->integer()->null()->comment('Клиент: человек')->after('cms_deal_id'));
        $this->createIndex('cms_proposal__user', '{{%cms_proposal}}', ['cms_user_id']);
        $this->addForeignKey('cms_proposal__cms_user_id', '{{%cms_proposal}}', 'cms_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
    }

    public function safeDown()
    {
        $this->dropForeignKey('cms_proposal__cms_user_id', '{{%cms_proposal}}');
        $this->dropIndex('cms_proposal__user', '{{%cms_proposal}}');
        $this->dropColumn('{{%cms_proposal}}', 'cms_user_id');
    }
}
