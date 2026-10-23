<?php

use yii\db\Migration;

/**
 * The client page of a proposal: who accepted it (the version and the
 * sending link it was accepted from), the recipient name of a sending, and
 * the questions the client asked on the page.
 */
class m261025_120000__alter_tables__cms_proposal_client extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $this->addColumn('{{%cms_proposal_send}}', 'recipient_name', $this->string(255)->null()->comment('Имя получателя из CRM')->after('recipient'));

        $this->addColumn('{{%cms_proposal}}', 'accepted_at', $this->integer()->null()->comment('Когда клиент принял КП'));
        $this->addColumn('{{%cms_proposal}}', 'accepted_version_id', $this->integer()->null()->comment('Принятая версия'));
        $this->addColumn('{{%cms_proposal}}', 'accepted_send_id', $this->integer()->null()->comment('Отправка, по ссылке которой принято'));
        $this->addForeignKey('cms_proposal__accepted_version_id', '{{%cms_proposal}}', 'accepted_version_id', '{{%cms_proposal_version}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal__accepted_send_id', '{{%cms_proposal}}', 'accepted_send_id', '{{%cms_proposal_send}}', 'id', 'SET NULL', 'SET NULL');

        $table = '{{%cms_proposal_question}}';
        $this->createTable($table, [
            'id'                      => $this->primaryKey(),
            'created_at'              => $this->integer()->notNull(),
            'cms_proposal_id'         => $this->integer()->notNull(),
            'cms_proposal_send_id'    => $this->integer()->notNull(),
            'cms_proposal_version_id' => $this->integer()->null()->comment('Версия, которую смотрел клиент'),
            'name'                    => $this->string(255)->null(),
            'contact'                 => $this->string(255)->null()->comment('Как связаться: телефон или email'),
            'text'                    => $this->text()->notNull(),
            'cms_comm_message_id'     => $this->integer()->null()->comment('Сообщение в переписке CRM'),
            'ip'                      => $this->string(45)->null(),
            'user_agent'              => $this->string(500)->null(),
        ], $tableOptions);
        $this->createIndex('cms_proposal_question__proposal', $table, ['cms_proposal_id', 'created_at']);
        $this->createIndex('cms_proposal_question__send', $table, ['cms_proposal_send_id', 'created_at']);
        $this->createIndex('cms_proposal_question__ip', $table, ['ip', 'created_at']);
        $this->addForeignKey('cms_proposal_question__proposal_id', $table, 'cms_proposal_id', '{{%cms_proposal}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_proposal_question__send_id', $table, 'cms_proposal_send_id', '{{%cms_proposal_send}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_proposal_question__version_id', $table, 'cms_proposal_version_id', '{{%cms_proposal_version}}', 'id', 'SET NULL', 'SET NULL');
        $this->addCommentOnTable($table, 'Вопросы клиентов со страницы КП');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_proposal_question}}');
        $this->dropForeignKey('cms_proposal__accepted_send_id', '{{%cms_proposal}}');
        $this->dropForeignKey('cms_proposal__accepted_version_id', '{{%cms_proposal}}');
        $this->dropColumn('{{%cms_proposal}}', 'accepted_send_id');
        $this->dropColumn('{{%cms_proposal}}', 'accepted_version_id');
        $this->dropColumn('{{%cms_proposal}}', 'accepted_at');
        $this->dropColumn('{{%cms_proposal_send}}', 'recipient_name');
    }
}
