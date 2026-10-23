<?php

use yii\db\Migration;

/**
 * One registry of proposals with two kinds: a commercial proposal (versions,
 * items, "Принять") and a presentation (a page of a site the client is led
 * to through the personal link). Existing rows are commercial proposals.
 *
 * - cms_proposal: kind, the target page and the manager's text of a
 *   presentation;
 * - cms_proposal_send: a presentation has no versions, so the version of a
 *   sending is optional;
 * - cms_proposal_view: an opening that looks like a link check (seconds after
 *   sending, a data center, an automated browser) is kept with its reason and
 *   not counted;
 * - cms_proposal_task: tasks of the responsible employee made by the rule
 *   "задача при первом открытии", to find an open one before making another.
 */
class m261026_100000__alter_tables__cms_proposal_presentation extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $this->addColumn('{{%cms_proposal}}', 'kind', $this->string(32)->notNull()->defaultValue('proposal')->comment('Вид: proposal — КП, presentation — Презентация')->after('cms_site_id'));
        $this->addColumn('{{%cms_proposal}}', 'target_url', $this->string(1000)->null()->comment('Презентация: страница, на которую переводит ссылка'));
        $this->addColumn('{{%cms_proposal}}', 'message', $this->text()->null()->comment('Презентация: короткий текст менеджера'));
        $this->createIndex('cms_proposal__kind', '{{%cms_proposal}}', ['cms_site_id', 'kind']);

        $this->alterColumn('{{%cms_proposal_send}}', 'cms_proposal_version_id', $this->integer()->null()->comment('Отправленная версия КП (у презентации версий нет)'));

        $this->addColumn('{{%cms_proposal_view}}', 'check_reason', $this->string(32)->null()->comment('Похоже на проверку ссылок: early, network, browser; не засчитано'));

        $table = '{{%cms_proposal_task}}';
        $this->createTable($table, [
            'id'                   => $this->primaryKey(),
            'created_at'           => $this->integer()->notNull(),
            'cms_proposal_id'      => $this->integer()->notNull(),
            'cms_proposal_send_id' => $this->integer()->null()->comment('Отправка, которую открыл клиент'),
            'cms_task_id'          => $this->integer()->notNull(),
        ], $tableOptions);
        $this->createIndex('cms_proposal_task__task', $table, ['cms_task_id'], true);
        $this->createIndex('cms_proposal_task__proposal', $table, ['cms_proposal_id']);
        $this->addForeignKey('cms_proposal_task__proposal_id', $table, 'cms_proposal_id', '{{%cms_proposal}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_proposal_task__send_id', $table, 'cms_proposal_send_id', '{{%cms_proposal_send}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal_task__task_id', $table, 'cms_task_id', '{{%cms_task}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($table, 'Задачи менеджеру по открытию предложений');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_proposal_task}}');
        $this->dropColumn('{{%cms_proposal_view}}', 'check_reason');

        // Presentations cannot exist without the new columns; their sendings have no version.
        $this->delete('{{%cms_proposal}}', ['kind' => 'presentation']);
        $this->delete('{{%cms_proposal_send}}', ['cms_proposal_version_id' => null]);
        $this->alterColumn('{{%cms_proposal_send}}', 'cms_proposal_version_id', $this->integer()->notNull());

        $this->dropIndex('cms_proposal__kind', '{{%cms_proposal}}');
        $this->dropColumn('{{%cms_proposal}}', 'message');
        $this->dropColumn('{{%cms_proposal}}', 'target_url');
        $this->dropColumn('{{%cms_proposal}}', 'kind');
    }
}
