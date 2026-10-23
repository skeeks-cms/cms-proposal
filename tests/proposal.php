<?php
/**
 * Proposals on an isolated SQLite memory database: item rows and totals,
 * numbering per site, the first version from a template, our requisites
 * copied into the version, editing an unsent version in place, a sent
 * version that never changes (a new version instead), "Новая версия",
 * feed entries of the client and its company, the client rule, the record
 * source value and the rich-text cleaning of intro and terms; then
 * tests/client.php (stage C) and tests/presentation.php (stage E). No site
 * schema, no network.
 *
 * Usage: php tests/proposal.php [vendor/autoload.php]
 */

$autoloadCandidates = array_filter([$argv[1] ?? null, dirname(__DIR__, 3).'/autoload.php', '/app/vendor/autoload.php']);
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        require_once dirname($autoload).'/yiisoft/yii2/Yii.php';
        break;
    }
}
Yii::setAlias('@skeeks/cms/proposal', dirname(__DIR__).'/src');
// Run against the package source even when the application autoloads another copy.
spl_autoload_register(static function ($class) {
    $prefix = 'skeeks\\cms\\proposal\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalItem;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalTemplate;
use skeeks\cms\proposal\models\CmsProposalVersion;
use skeeks\cms\proposal\services\ProposalFeed;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\services\ProposalVersions;
use skeeks\cms\proposal\source\ProposalClientSource;

function proposalExpect($condition, string $message): void
{
    if (!$condition) {
        // Errors the services logged instead of throwing (the client page must not fail) explain the failure.
        foreach (Yii::getLogger()->messages as $logged) {
            if ($logged[1] === \yii\log\Logger::LEVEL_ERROR) {
                fwrite(STDERR, 'Logged: '.mb_substr(is_string($logged[0]) ? $logged[0] : print_r($logged[0], true), 0, 800)."\n");
            }
        }
        throw new RuntimeException($message);
    }
}

new \yii\console\Application([
    'id' => 'cms-proposal-test',
    'basePath' => sys_get_temp_dir(),
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
        // Core models translate their messages; the test reads them as written.
        'i18n' => ['translations' => ['*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => sys_get_temp_dir()]]],
    ],
]);

// 1. Rows and totals, as the bill counts them.
$rows = ProposalItems::normalize([
    ['type' => 'section', 'name' => '  Дизайн '],
    ['name' => 'Макет главной', 'quantity' => '2', 'price' => '10 000', 'discount_value' => '10%'],
    ['name' => 'Адаптив', 'quantity' => '1,5', 'price' => '4000,50', 'discount_value' => '500'],
    ['type' => 'section', 'name' => ''],
    ['name' => '', 'price' => '', 'quantity' => 3],
    ['type' => 'section', 'name' => 'Разработка'],
    ['name' => 'Вёрстка', 'quantity' => 0, 'price' => 30000, 'discount_value' => '100000'],
]);
proposalExpect(count($rows) === 5, 'Empty lines and nameless sections must be dropped.');
proposalExpect($rows[0] === ['type' => 'section', 'name' => 'Дизайн'], 'A section keeps only its name.');
proposalExpect($rows[1]['amount'] == 18000 && $rows[1]['discount_amount'] == 2000, 'A percent discount is taken from quantity × price.');
proposalExpect(abs($rows[2]['amount'] - (6000.75 - 750)) < 0.001, 'A sum discount is per unit; numbers with commas and spaces are parsed.');
proposalExpect($rows[4]['quantity'] == 1 && $rows[4]['amount'] == 0, 'Zero quantity becomes 1 and a discount never exceeds the base.');
proposalExpect($rows[1]['measure_name'] === 'шт' && $rows[1]['vat_name'] === 'Без НДС', 'Unit and VAT label have defaults.');
$totals = ProposalItems::totals($rows, '5%');
proposalExpect(abs($totals['subtotal'] - 23250.75) < 0.001, 'The subtotal sums the lines, not the sections.');
proposalExpect(abs($totals['discount'] - 1162.5375) < 0.001 && abs($totals['total'] - 22088.2125) < 0.001, 'The discount on the whole proposal is a percent of the subtotal.');
proposalExpect(ProposalItems::totals($rows, '999999')['total'] == 0, 'The discount on the whole proposal never makes the total negative.');
$groups = ProposalItems::groups($rows);
proposalExpect(count($groups) === 2 && $groups[0]['name'] === 'Дизайн' && count($groups[0]['rows']) === 2 && $groups[1]['amount'] == 0, 'Rows are grouped under their sections.');
proposalExpect(ProposalItems::money(12500) === '12 500 ₽' && ProposalItems::money(10.5, 'USD') === '10,50 $', 'Money is formatted for people.');

// 2. Schema: only the columns the models read.
$db = Yii::$app->db;
$db->createCommand()->createTable('cms_user', ['id' => 'pk', 'username' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'is_company' => 'integer default 0', 'company_name' => 'string', 'alias' => 'string', 'email' => 'string', 'phone' => 'string', 'is_worker' => 'integer', 'is_active' => 'integer not null default 1', 'work_shedule' => 'text', 'patronymic' => 'string', 'image_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_company', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'name' => 'string'])->execute();
$db->createCommand()->createTable('cms_lead', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'name' => 'string', 'cms_company_id' => 'integer', 'cms_user_id' => 'integer', 'executor_id' => 'integer', 'status' => 'string', 'source_type' => 'string', 'source_data' => 'text'])->execute();
$db->createCommand()->createTable('cms_deal', ['id' => 'pk', 'created_at' => 'integer', 'name' => 'string', 'cms_company_id' => 'integer', 'currency_code' => 'string'])->execute();
$db->createCommand()->createTable('cms_contractor', ['id' => 'pk', 'cms_site_id' => 'integer', 'is_our' => 'integer default 0', 'contractor_type' => 'string', 'name' => 'string', 'full_name' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'patronymic' => 'string', 'inn' => 'string', 'kpp' => 'string', 'ogrn' => 'string', 'address' => 'string', 'mailing_postcode' => 'string'])->execute();
$db->createCommand()->createTable('cms_log', ['id' => 'pk', 'created_by' => 'integer', 'created_at' => 'integer', 'updated_by' => 'integer', 'updated_at' => 'integer', 'cms_company_id' => 'integer', 'cms_user_id' => 'integer', 'log_type' => 'string', 'comment' => 'text', 'model_code' => 'string', 'model_id' => 'integer', 'model_as_text' => 'string', 'data' => 'text', 'sub_model_code' => 'string', 'sub_model_id' => 'integer', 'sub_model_log_type' => 'string', 'sub_model_as_text' => 'string', 'is_pinned' => 'integer default 0'])->execute();
$db->createCommand()->createTable('cms_log_file', ['id' => 'pk', 'cms_log_id' => 'integer', 'storage_file_id' => 'integer', 'priority' => 'integer'])->execute();
$by = ['created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
$db->createCommand()->createTable('cms_proposal_template', ['id' => 'pk'] + $by + ['cms_site_id' => 'integer not null', 'name' => 'string not null', 'title' => 'string', 'intro' => 'text', 'terms' => 'text', 'items_data' => 'text', 'discount_value' => 'string', 'valid_days' => 'integer', 'is_active' => 'integer not null default 1', 'priority' => 'integer not null default 100'])->execute();
$db->createCommand()->createTable('cms_proposal', ['id' => 'pk'] + $by + ['cms_site_id' => 'integer not null', 'kind' => "string not null default 'proposal'", 'target_url' => 'string', 'message' => 'text', 'number' => 'integer not null', 'name' => 'string not null', 'cms_lead_id' => 'integer', 'cms_company_id' => 'integer', 'cms_deal_id' => 'integer', 'cms_user_id' => 'integer', 'responsible_id' => 'integer', 'status' => "string not null default 'draft'", 'status_at' => 'integer', 'valid_until' => 'integer', 'currency_code' => "string not null default 'RUB'", 'current_version_id' => 'integer', 'cms_proposal_template_id' => 'integer', 'accepted_at' => 'integer', 'accepted_version_id' => 'integer', 'accepted_send_id' => 'integer'])->execute();
$db->createCommand()->createIndex('cms_proposal__site_number', 'cms_proposal', ['cms_site_id', 'number'], true)->execute();
$contractorColumns = [];
foreach (['type', 'name', 'full_name', 'inn', 'kpp', 'ogrn', 'address', 'mailing_postcode'] as $field) {
    $contractorColumns['our_contractor_'.$field] = 'string';
}
$db->createCommand()->createTable('cms_proposal_version', ['id' => 'pk'] + $by + ['cms_proposal_id' => 'integer not null', 'version' => 'integer not null', 'intro' => 'text', 'terms' => 'text', 'currency_code' => "string not null default 'RUB'", 'subtotal_amount' => 'decimal(18,4) not null default 0', 'discount_value' => 'string', 'discount_amount' => 'decimal(18,4) not null default 0', 'amount' => 'decimal(18,4) not null default 0', 'our_contractor_id' => 'integer'] + $contractorColumns + ['sent_at' => 'integer', 'pdf_storage_file_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_proposal_item', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_proposal_version_id' => 'integer not null', 'type' => "string not null default 'item'", 'shop_product_id' => 'integer', 'name' => 'string not null', 'description' => 'text', 'measure_name' => 'string', 'quantity' => 'decimal(18,4) not null default 1', 'price' => 'decimal(18,4) not null default 0', 'discount_value' => 'string', 'discount_amount' => 'decimal(18,4) not null default 0', 'amount' => 'decimal(18,4) not null default 0', 'vat_name' => 'string', 'sort' => 'integer not null default 100'])->execute();
$db->createCommand()->createTable('cms_proposal_send', ['id' => 'pk', 'created_by' => 'integer', 'created_at' => 'integer', 'cms_proposal_id' => 'integer not null', 'cms_proposal_version_id' => 'integer', 'channel' => 'string not null', 'recipient' => 'string', 'recipient_name' => 'string', 'cms_comm_message_id' => 'integer', 'code' => 'string not null', 'first_viewed_at' => 'integer', 'last_viewed_at' => 'integer', 'views_count' => 'integer not null default 0', 'cms_lead_id' => 'integer', 'cms_company_id' => 'integer', 'cms_deal_id' => 'integer', 'cms_user_id' => 'integer'])->execute();

$db->createCommand()->batchInsert('cms_user', ['id', 'username', 'is_worker'], [[1, 'semenov', 1]])->execute();
$db->createCommand()->batchInsert('cms_company', ['id', 'name'], [[10, 'ООО «Ромашка»'], [11, 'ООО «Лютик»']])->execute();
$db->createCommand()->batchInsert('cms_lead', ['id', 'cms_site_id', 'name', 'cms_company_id', 'status', 'source_type'], [[20, 1, 'Сайт для пекарни', 10, 'new', 'manual'], [21, 1, 'Без компании', null, 'new', 'manual']])->execute();
$db->createCommand()->batchInsert('cms_contractor', ['id', 'cms_site_id', 'is_our', 'contractor_type', 'name', 'full_name', 'inn', 'kpp', 'ogrn', 'address'], [
    [30, 1, 1, 'legal', 'ООО «Скикс»', 'Общество с ограниченной ответственностью «Скикс»', '7700000000', '770001001', '1027700000000', 'Москва, ул. Тверская, 1'],
    [31, 2, 1, 'legal', 'ООО «Другой сайт»', null, '5000000000', null, null, null],
])->execute();

// 3. A template and a proposal of a lead made from it.
$template = new CmsProposalTemplate(['cms_site_id' => 1, 'name' => 'Сайт под ключ', 'title' => 'Разработка сайта', 'valid_days' => 30, 'discount_value' => '10%',
    'intro' => '<p>Здравствуйте!</p><script>alert(1)</script>', 'terms' => '<p>Оплата 50/50.</p>']);
$template->itemsJson = json_encode([['type' => 'section', 'name' => 'Дизайн'], ['name' => 'Макет', 'quantity' => 1, 'price' => 50000], ['name' => 'Вёрстка', 'quantity' => 2, 'price' => 25000]]);
proposalExpect($template->save(), 'The template must save: '.json_encode($template->errors, JSON_UNESCAPED_UNICODE));
proposalExpect(!str_contains((string)$template->intro, 'script'), 'Template texts are cleaned like every rich-text field.');
proposalExpect(count($template->itemRows) === 3, 'Template items are kept as editor rows.');

$proposal = new CmsProposal(['cms_site_id' => 1, 'cms_lead_id' => 20, 'responsible_id' => 1, 'cms_proposal_template_id' => (int)$template->id]);
proposalExpect($proposal->save(), 'A proposal from a template must save: '.json_encode($proposal->errors, JSON_UNESCAPED_UNICODE));
$proposal->refresh();
proposalExpect((int)$proposal->number === 1 && $proposal->name === 'Разработка сайта', 'The first proposal of a site gets number 1 and the template title.');
proposalExpect($proposal->status === CmsProposal::STATUS_DRAFT && $proposal->valid_until >= strtotime('today +30 days'), 'A new proposal is a draft valid for the template days.');
$v1 = $proposal->currentVersion;
proposalExpect($v1 && (int)$v1->version === 1 && count($v1->items) === 3, 'The first version copies the template items.');
proposalExpect((float)$v1->subtotal_amount == 100000 && (float)$v1->discount_amount == 10000 && (float)$v1->amount == 90000, 'Version totals follow the items and the template discount.');
proposalExpect((int)$v1->our_contractor_id === 30 && str_contains((string)$v1->our_contractor_full_name, 'Скикс') && $v1->our_contractor_inn === '7700000000', 'Our requisites of the site are copied into the version.');
proposalExpect(str_contains((string)$v1->intro, 'Здравствуйте') && !str_contains((string)$v1->intro, 'script'), 'The intro comes from the template.');

$logs = CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE])->orderBy(['id' => SORT_ASC])->all();
proposalExpect(count($logs) === 2, 'A lead proposal is written to the feed of the lead and of its company.');
proposalExpect($logs[0]->model_code === CmsLead::class && (int)$logs[0]->model_id === 20 && $logs[1]->model_code === CmsCompany::class && (int)$logs[1]->model_id === 10 && (int)$logs[1]->cms_company_id === 10, 'Feed entries point at the lead and its company.');
proposalExpect(($logs[0]->data['event'] ?? null) === ProposalFeed::EVENT_CREATED && (int)($logs[0]->data['id'] ?? 0) === (int)$proposal->id, 'The entry keeps the proposal and the event.');

$second = new CmsProposal(['cms_site_id' => 1, 'cms_company_id' => 11]);
proposalExpect($second->save(), 'A proposal of a company must save: '.json_encode($second->errors, JSON_UNESCAPED_UNICODE));
proposalExpect((int)$second->number === 2 && $second->name === 'ООО «Лютик»', 'Numbers go on within the site; the name comes from the client.');
proposalExpect($second->currentVersion && !$second->currentVersion->items && (float)$second->currentVersion->amount == 0, 'Without a template the first version is empty.');
proposalExpect(CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_id' => 11])->count() == 1, 'A company proposal is written once.');
$other = new CmsProposal(['cms_site_id' => 2, 'cms_company_id' => 10, 'name' => 'Другой сайт']);
proposalExpect($other->save() && (int)$other->number === 1 && (int)$other->currentVersion->our_contractor_id === 31, 'Every site numbers its proposals and uses its own requisites.');

// 4. The client: exactly one of lead, company, deal.
$none = new CmsProposal(['cms_site_id' => 1, 'name' => 'Без клиента']);
proposalExpect(!$none->validate() && $none->hasErrors('cms_lead_id'), 'A proposal needs a client.');
$two = new CmsProposal(['cms_site_id' => 1, 'name' => 'Два клиента', 'cms_lead_id' => 21, 'cms_company_id' => 10]);
proposalExpect(!$two->validate() && $two->hasErrors('cms_lead_id'), 'A proposal has one client.');

// 5. Editing an unsent version changes it in place.
$service = new ProposalVersions();
$content = ProposalVersions::contentOf($v1);
$content['items'][] = ['name' => 'Хостинг на год', 'quantity' => 1, 'price' => 6000];
$content['discount_value'] = '';
$saved = $service->saveContent($proposal, $content);
proposalExpect((int)$saved->id === (int)$v1->id && CmsProposalVersion::find()->andWhere(['cms_proposal_id' => $proposal->id])->count() == 1, 'An unsent version is edited in place.');
proposalExpect(count($saved->items) === 4 && (float)$saved->amount == 106000, 'Edited items and totals are saved.');
proposalExpect(CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE])->count() == 4, 'Editing a draft writes nothing to the feed.');

// 6. A sent version never changes; editing makes a new version.
$saved->markSent();
$saved->refresh();
proposalExpect($saved->isLocked, 'A version is locked once sent.');
$saved->intro = '<p>Другой текст</p>';
proposalExpect(!$saved->save() && $saved->hasErrors('sent_at'), 'A sent version refuses changes.');
$saved->refresh();
$item = $saved->items[0];
$item->name = 'Изменено';
proposalExpect(!$item->save() && !$item->delete(), 'Items of a sent version can be neither changed nor deleted.');
proposalExpect(!$saved->delete(), 'A sent version cannot be deleted.');
$saved->pdf_storage_file_id = 77;
proposalExpect($saved->save(), 'The PDF made at the first sending can still be attached to a sent version.');

$send = new CmsProposalSend(['cms_proposal_id' => $proposal->id, 'cms_proposal_version_id' => $saved->id, 'channel' => CmsProposalSend::CHANNEL_EMAIL, 'recipient' => 'client@example.com']);
proposalExpect($send->save() && strlen($send->code) >= 32, 'A sending gets its own secret link code.');

$content = ProposalVersions::contentOf($saved);
$content['items'][1]['price'] = 60000;
$proposal->refresh();
$v2 = $service->saveContent($proposal, $content);
proposalExpect((int)$v2->version === 2 && (int)$v2->id !== (int)$saved->id, 'Editing a sent proposal makes version 2.');
$proposal->refresh();
proposalExpect((int)$proposal->current_version_id === (int)$v2->id, 'The new version becomes current.');
$saved = CmsProposalVersion::findOne($saved->id);
proposalExpect((float)$saved->amount == 106000 && (float)$saved->items[1]->price == 50000, 'The sent version keeps its content.');
proposalExpect((float)$v2->amount == 116000, 'The new version has the changes.');
$versionLog = CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE])->orderBy(['id' => SORT_DESC])->one();
proposalExpect(($versionLog->data['event'] ?? null) === ProposalFeed::EVENT_VERSION && (int)($versionLog->data['version'] ?? 0) === 2, 'A new version is written to the feed.');

// 7. "Новая версия": a copy of the current one.
$v3 = $service->newVersion($proposal);
$proposal->refresh();
proposalExpect((int)$v3->version === 3 && (int)$proposal->current_version_id === (int)$v3->id && count($v3->items) === count($v2->items) && (float)$v3->amount == (float)$v2->amount, 'A new version copies the current one and becomes current.');
proposalExpect(CmsProposalItem::find()->andWhere(['cms_proposal_version_id' => $v2->id])->count() == count($v2->items), 'The copied version keeps its own items.');

// 8. The record source of lead, company and deal cards.
proposalExpect(ProposalClientSource::value(CmsLead::findOne(20)) === 'proposal-client:lead.20', 'A lead card opens the create window with ?source=proposal-client:lead.ID.');
proposalExpect(ProposalClientSource::value(CmsCompany::findOne(10)) === 'proposal-client:company.10', 'A company card has its own source value.');
proposalExpect(\skeeks\cms\source\RecordSources::parse('proposal-client:lead.20') === ['proposal-client', 'lead.20'], 'The value is valid for core RecordSources.');
proposalExpect((new ProposalClientSource())->supports(CmsProposal::class) && !(new ProposalClientSource())->supports(CmsLead::class), 'The source creates proposals only.');
proposalExpect((new ProposalClientSource())->find('lead.20') === null, 'Without a signed-in employee the source finds nothing.');

// 9. Expiry is shown without changing the stored status.
$proposal->valid_until = time() - 60;
proposalExpect($proposal->isExpired && $proposal->displayStatus === CmsProposal::STATUS_EXPIRED && $proposal->status === CmsProposal::STATUS_DRAFT, 'A waiting proposal past its date shows as expired.');
$proposal->status = CmsProposal::STATUS_ACCEPTED;
proposalExpect(!$proposal->isExpired, 'An accepted proposal does not expire.');

// 10-13: sending, views, "Принять", "Задать вопрос" (stage C), the task of the first view.
require __DIR__.'/client.php';

// 14-18: presentations, link checks, the rule "задача при первом открытии" (stage E).
require __DIR__.'/presentation.php';

echo "OK: cms-proposal\n";
