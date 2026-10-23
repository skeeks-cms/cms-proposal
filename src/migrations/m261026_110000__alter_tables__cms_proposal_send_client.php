<?php

use yii\db\Migration;

/**
 * The client of a sending. A presentation may be one for everybody (no
 * client of its own): every sending then belongs to the CRM record it was
 * sent to (a lead, a company, a deal or a client person), and the feed,
 * the notification and the task of an opening go to that client. Existing
 * sendings get the client of their proposal.
 */
class m261026_110000__alter_tables__cms_proposal_send_client extends Migration
{
    public function safeUp()
    {
        $table = '{{%cms_proposal_send}}';
        $this->addColumn($table, 'cms_lead_id', $this->integer()->null()->comment('Клиент отправки: лид'));
        $this->addColumn($table, 'cms_company_id', $this->integer()->null()->comment('Клиент отправки: компания'));
        $this->addColumn($table, 'cms_deal_id', $this->integer()->null()->comment('Клиент отправки: сделка'));
        $this->addColumn($table, 'cms_user_id', $this->integer()->null()->comment('Клиент отправки: человек'));
        foreach (['cms_lead_id' => 'cms_lead', 'cms_company_id' => 'cms_company', 'cms_deal_id' => 'cms_deal', 'cms_user_id' => 'cms_user'] as $column => $target) {
            $this->createIndex('cms_proposal_send__'.$column, $table, [$column]);
            $this->addForeignKey('cms_proposal_send__'.$column, $table, $column, '{{%'.$target.'}}', 'id', 'SET NULL', 'SET NULL');
        }

        foreach (['cms_lead_id', 'cms_company_id', 'cms_deal_id', 'cms_user_id'] as $column) {
            $this->execute('UPDATE '.$this->db->quoteTableName($table).' SET '.$this->db->quoteColumnName($column)
                .' = (SELECT p.'.$this->db->quoteColumnName($column).' FROM '.$this->db->quoteTableName('{{%cms_proposal}}')
                .' p WHERE p.id = cms_proposal_id)');
        }
    }

    public function safeDown()
    {
        $table = '{{%cms_proposal_send}}';
        foreach (['cms_user_id', 'cms_deal_id', 'cms_company_id', 'cms_lead_id'] as $column) {
            $this->dropForeignKey('cms_proposal_send__'.$column, $table);
            $this->dropIndex('cms_proposal_send__'.$column, $table);
            $this->dropColumn($table, $column);
        }
    }
}
