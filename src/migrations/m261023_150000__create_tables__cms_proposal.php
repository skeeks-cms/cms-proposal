<?php

use yii\db\Migration;

/**
 * Commercial proposals: the proposal (client, responsible, status, current
 * version), its versions (texts, totals, copy of our requisites; a sent
 * version never changes), items with sections, sendings with a secret link
 * code, page views and templates.
 *
 * Items keep shop_product_id without a foreign key: skeeks/cms-shop is
 * optional. Sendings keep the CRM message id the same way (cms-communication).
 */
class m261023_150000__create_tables__cms_proposal extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = '{{%cms_proposal_template}}';
        $this->createTable($table, [
            'id'             => $this->primaryKey(),
            'created_by'     => $this->integer()->null(),
            'updated_by'     => $this->integer()->null(),
            'created_at'     => $this->integer()->null(),
            'updated_at'     => $this->integer()->null(),
            'cms_site_id'    => $this->integer()->notNull(),
            'name'           => $this->string(255)->notNull()->comment('Название шаблона'),
            'title'          => $this->string(255)->null()->comment('Название нового КП'),
            'intro'          => $this->text()->null()->comment('Вступление (HTML)'),
            'terms'          => $this->text()->null()->comment('Условия (HTML)'),
            'items_data'     => $this->text()->null()->comment('Позиции и разделы (JSON)'),
            'discount_value' => $this->string(32)->null()->comment('Скидка на всё: сумма или процент'),
            'valid_days'     => $this->integer()->null()->comment('Срок действия, дней'),
            'is_active'      => $this->boolean()->notNull()->defaultValue(1),
            'priority'       => $this->integer()->notNull()->defaultValue(100),
        ], $tableOptions);
        $this->createIndex('cms_proposal_template__site', $table, ['cms_site_id', 'is_active', 'priority']);
        $this->addForeignKey('cms_proposal_template__cms_site_id', $table, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_proposal_template__created_by', $table, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal_template__updated_by', $table, 'updated_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addCommentOnTable($table, 'Шаблоны коммерческих предложений');

        $table = '{{%cms_proposal}}';
        $this->createTable($table, [
            'id'                       => $this->primaryKey(),
            'created_by'               => $this->integer()->null(),
            'updated_by'               => $this->integer()->null(),
            'created_at'               => $this->integer()->null(),
            'updated_at'               => $this->integer()->null(),
            'cms_site_id'              => $this->integer()->notNull(),
            'number'                   => $this->integer()->notNull()->comment('Номер КП на сайте'),
            'name'                     => $this->string(255)->notNull()->comment('Название'),
            'cms_lead_id'              => $this->integer()->null()->comment('Клиент: лид'),
            'cms_company_id'           => $this->integer()->null()->comment('Клиент: компания'),
            'cms_deal_id'              => $this->integer()->null()->comment('Клиент: сделка'),
            'responsible_id'           => $this->integer()->null()->comment('Ответственный'),
            'status'                   => $this->string(32)->notNull()->defaultValue('draft'),
            'status_at'                => $this->integer()->null()->comment('Когда установлен статус'),
            'valid_until'              => $this->integer()->null()->comment('Действует до'),
            'currency_code'            => $this->string(3)->notNull()->defaultValue('RUB'),
            'current_version_id'       => $this->integer()->null()->comment('Текущая версия'),
            'cms_proposal_template_id' => $this->integer()->null()->comment('Шаблон, из которого создано'),
        ], $tableOptions);
        $this->createIndex('cms_proposal__site_number', $table, ['cms_site_id', 'number'], true);
        $this->createIndex('cms_proposal__site_status', $table, ['cms_site_id', 'status']);
        $this->createIndex('cms_proposal__lead', $table, ['cms_lead_id']);
        $this->createIndex('cms_proposal__company', $table, ['cms_company_id']);
        $this->createIndex('cms_proposal__deal', $table, ['cms_deal_id']);
        $this->createIndex('cms_proposal__responsible', $table, ['responsible_id']);
        $this->addForeignKey('cms_proposal__cms_site_id', $table, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_proposal__cms_lead_id', $table, 'cms_lead_id', '{{%cms_lead}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal__cms_company_id', $table, 'cms_company_id', '{{%cms_company}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal__cms_deal_id', $table, 'cms_deal_id', '{{%cms_deal}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal__responsible_id', $table, 'responsible_id', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal__created_by', $table, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal__updated_by', $table, 'updated_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal__template_id', $table, 'cms_proposal_template_id', '{{%cms_proposal_template}}', 'id', 'SET NULL', 'SET NULL');
        $this->addCommentOnTable($table, 'Коммерческие предложения');

        $table = '{{%cms_proposal_version}}';
        $contractor = [];
        foreach (['type' => 32, 'name' => 255, 'full_name' => 255, 'inn' => 32, 'kpp' => 32, 'ogrn' => 32, 'address' => 1000, 'mailing_postcode' => 32] as $field => $length) {
            $contractor['our_contractor_'.$field] = $this->string($length)->null();
        }
        $this->createTable($table, array_merge([
            'id'                  => $this->primaryKey(),
            'created_by'          => $this->integer()->null(),
            'updated_by'          => $this->integer()->null(),
            'created_at'          => $this->integer()->null(),
            'updated_at'          => $this->integer()->null(),
            'cms_proposal_id'     => $this->integer()->notNull(),
            'version'             => $this->integer()->notNull()->comment('Номер версии'),
            'intro'               => $this->text()->null()->comment('Вступление (HTML)'),
            'terms'               => $this->text()->null()->comment('Условия (HTML)'),
            'currency_code'       => $this->string(3)->notNull()->defaultValue('RUB'),
            'subtotal_amount'     => $this->decimal(18, 4)->notNull()->defaultValue(0)->comment('Сумма позиций'),
            'discount_value'      => $this->string(32)->null()->comment('Скидка на всё: сумма или процент'),
            'discount_amount'     => $this->decimal(18, 4)->notNull()->defaultValue(0)->comment('Скидка на всё, сумма'),
            'amount'              => $this->decimal(18, 4)->notNull()->defaultValue(0)->comment('Итого'),
            'our_contractor_id'   => $this->integer()->null()->comment('Реквизиты нашей компании'),
        ], $contractor, [
            'sent_at'             => $this->integer()->null()->comment('Первая отправка: с этого момента версия не меняется'),
            'pdf_storage_file_id' => $this->integer()->null()->comment('PDF, сделанный при первой отправке'),
        ]), $tableOptions);
        $this->createIndex('cms_proposal_version__proposal_version', $table, ['cms_proposal_id', 'version'], true);
        $this->addForeignKey('cms_proposal_version__proposal_id', $table, 'cms_proposal_id', '{{%cms_proposal}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_proposal_version__our_contractor_id', $table, 'our_contractor_id', '{{%cms_contractor}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal_version__pdf_file_id', $table, 'pdf_storage_file_id', '{{%cms_storage_file}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal_version__created_by', $table, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_proposal_version__updated_by', $table, 'updated_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addCommentOnTable($table, 'Версии коммерческих предложений');

        $this->addForeignKey('cms_proposal__current_version_id', '{{%cms_proposal}}', 'current_version_id', $table, 'id', 'SET NULL', 'SET NULL');

        $table = '{{%cms_proposal_item}}';
        $this->createTable($table, [
            'id'                      => $this->primaryKey(),
            'created_at'              => $this->integer()->null(),
            'updated_at'              => $this->integer()->null(),
            'cms_proposal_version_id' => $this->integer()->notNull(),
            'type'                    => $this->string(16)->notNull()->defaultValue('item')->comment('item — позиция, section — раздел'),
            'shop_product_id'         => $this->integer()->null()->comment('Товар или услуга cms-shop'),
            'name'                    => $this->string(255)->notNull(),
            'description'             => $this->text()->null(),
            'measure_name'            => $this->string(50)->null(),
            'quantity'                => $this->decimal(18, 4)->notNull()->defaultValue(1),
            'price'                   => $this->decimal(18, 4)->notNull()->defaultValue(0),
            'discount_value'          => $this->string(32)->null(),
            'discount_amount'         => $this->decimal(18, 4)->notNull()->defaultValue(0),
            'amount'                  => $this->decimal(18, 4)->notNull()->defaultValue(0),
            'vat_name'                => $this->string(32)->null(),
            'sort'                    => $this->integer()->notNull()->defaultValue(100),
        ], $tableOptions);
        $this->createIndex('cms_proposal_item__version_sort', $table, ['cms_proposal_version_id', 'sort']);
        $this->createIndex('cms_proposal_item__product', $table, ['shop_product_id']);
        $this->addForeignKey('cms_proposal_item__version_id', $table, 'cms_proposal_version_id', '{{%cms_proposal_version}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($table, 'Позиции и разделы версий КП');

        $table = '{{%cms_proposal_send}}';
        $this->createTable($table, [
            'id'                      => $this->primaryKey(),
            'created_by'              => $this->integer()->null()->comment('Кто отправил'),
            'created_at'              => $this->integer()->null()->comment('Когда отправлено'),
            'cms_proposal_id'         => $this->integer()->notNull(),
            'cms_proposal_version_id' => $this->integer()->notNull(),
            'channel'                 => $this->string(32)->notNull()->comment('Канал: email, sms, telegram…'),
            'recipient'               => $this->string(255)->null()->comment('Получатель: адрес, телефон'),
            'cms_comm_message_id'     => $this->integer()->null()->comment('Сообщение CRM'),
            'code'                    => $this->string(64)->notNull()->comment('Секретный код личной ссылки'),
            'first_viewed_at'         => $this->integer()->null(),
            'last_viewed_at'          => $this->integer()->null(),
            'views_count'             => $this->integer()->notNull()->defaultValue(0),
        ], $tableOptions);
        $this->createIndex('cms_proposal_send__code', $table, ['code'], true);
        $this->createIndex('cms_proposal_send__proposal', $table, ['cms_proposal_id', 'created_at']);
        $this->createIndex('cms_proposal_send__version', $table, ['cms_proposal_version_id']);
        $this->createIndex('cms_proposal_send__message', $table, ['cms_comm_message_id']);
        $this->addForeignKey('cms_proposal_send__proposal_id', $table, 'cms_proposal_id', '{{%cms_proposal}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_proposal_send__version_id', $table, 'cms_proposal_version_id', '{{%cms_proposal_version}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_proposal_send__created_by', $table, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addCommentOnTable($table, 'Отправки КП клиентам: у каждой своя ссылка');

        $table = '{{%cms_proposal_view}}';
        $this->createTable($table, [
            'id'                   => $this->primaryKey(),
            'created_at'           => $this->integer()->notNull(),
            'cms_proposal_send_id' => $this->integer()->notNull(),
            'user_agent'           => $this->string(500)->null(),
            'ip'                   => $this->string(45)->null(),
            'is_bot'               => $this->boolean()->notNull()->defaultValue(0)->comment('Робот предпросмотра, не засчитан'),
        ], $tableOptions);
        $this->createIndex('cms_proposal_view__send', $table, ['cms_proposal_send_id', 'created_at']);
        $this->addForeignKey('cms_proposal_view__send_id', $table, 'cms_proposal_send_id', '{{%cms_proposal_send}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($table, 'Просмотры страницы КП по отправкам');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_proposal_view}}');
        $this->dropTable('{{%cms_proposal_send}}');
        $this->dropTable('{{%cms_proposal_item}}');
        $this->dropForeignKey('cms_proposal__current_version_id', '{{%cms_proposal}}');
        $this->dropTable('{{%cms_proposal_version}}');
        $this->dropTable('{{%cms_proposal}}');
        $this->dropTable('{{%cms_proposal_template}}');
    }
}
