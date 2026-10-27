<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\mcp;

use skeeks\cms\mcp\tools\CallbackTool;
use skeeks\cms\mcp\tools\McpToolProviderInterface;
use yii\base\Component;

/**
 * MCP/REST read tools of proposals (skeeks/cms-mcp), registered through
 * Yii::$app->params['cmsMcp']['toolProviders'] in this package's config,
 * inert without cms-mcp. Creating and sending proposals is not here.
 */
class ProposalReadToolProvider extends Component implements McpToolProviderInterface
{
    public $serviceConfig = ProposalReadService::class;

    public function getTools(): array
    {
        $service = \Yii::createObject($this->serviceConfig);
        $contacts = ['include_contacts' => ['type' => 'boolean', 'description' => 'Адреса и телефоны получателей полностью; по умолчанию замаскированы.']];
        return [
            $this->tool('cms_proposal_list',
                'Предложения CRM — коммерческие предложения (kind=proposal, КП) и Презентации (kind=presentation, страница сайта по личной ссылке), видимые сотруднику, новые первыми. '
                .'Фильтры: клиент (record_type + record_id: сделка (lead), компания — вместе с её сделками и услугами (deal), клиент-человек; включая отправленные ему общие Презентации), shared=true — общие Презентации для всех, '
                .'kind, status (draft, sent, opened, accepted, rejected, expired), sent (true — отправлялись), responsible_id, q (номер или название), date_from/date_to (создание). '
                .'По каждому: номер, название, статус, клиент, сумма текущей версии КП или адрес страницы Презентации, отправки, честные открытия (без проверок ссылок почтовыми службами), принятие. '
                .'Пример: {"record_type":"company","record_id":15}.',
                'cms.communication.read', [$service, 'proposalList'], $this->object([
                    'record_type' => ['type' => 'string', 'enum' => ['lead', 'company', 'client']], 'record_id' => ['type' => 'integer'],
                    'cms_lead_id' => ['type' => 'integer'], 'cms_company_id' => ['type' => 'integer'], 'cms_user_id' => ['type' => 'integer'],
                    'shared' => ['type' => 'boolean'], 'kind' => ['type' => 'string', 'enum' => ['proposal', 'presentation']],
                    'status' => ['type' => ['string', 'array']], 'sent' => ['type' => 'boolean'], 'responsible_id' => ['type' => 'integer'],
                    'q' => ['type' => 'string'], 'date_from' => ['type' => ['string', 'integer']], 'date_to' => ['type' => ['string', 'integer']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0],
                ] + $contacts)),
            $this->tool('cms_proposal_get',
                'Карточка предложения. КП: версии, позиции выбранной версии (по умолчанию текущей; разделы, количество, цена, скидка, сумма), итог, вступление и условия. '
                .'Презентация: адрес страницы target_url и текст менеджера. Для обоих: отправки (дата, канал, получатель, версия, письмо cms_comm_message_id, открыта ли клиентом, просмотры, '
                .'link_checks_count — открытия почтовой службой, не клиентом), вопросы клиента со страницы, принятие (дата, версия). Личные ссылки отправок не выдаются: их открытие засчиталось бы как открытие клиентом. '
                .'Пример: {"id":26}.',
                'cms.communication.read', [$service, 'proposalGet'], $this->object([
                    'id' => ['type' => 'integer'], 'version' => ['type' => 'integer', 'description' => 'Номер версии КП; по умолчанию текущая.'],
                    'max_length' => ['type' => 'integer', 'minimum' => 300, 'maximum' => 10000],
                ] + $contacts, ['id'])),
            $this->tool('cms_proposal_library_get',
                'Готовые материалы для предложений: общие Презентации (страницы сайта для всех клиентов — например решение для сферы, с текстом менеджера и статистикой отправок и открытий) '
                .'и активные шаблоны КП (позиции с ценами, вступление, условия, скидка, срок действия). Подбирайте материал по сфере клиента.',
                'cms.communication.read', [$service, 'library'], $this->object(['include_inactive' => ['type' => 'boolean', 'description' => 'Показать и выключенные шаблоны.']])),
        ];
    }

    protected function tool(string $name, string $description, string $scope, $callback, array $schema): CallbackTool
    {
        return new CallbackTool(['name' => $name, 'description' => $description, 'requiredScope' => $scope, 'callback' => $callback, 'inputSchema' => $schema]);
    }

    protected function object(array $properties = [], array $required = []): array
    {
        $s = ['type' => 'object', 'properties' => $properties ?: new \stdClass(), 'additionalProperties' => true];
        if ($required) {
            $s['required'] = $required;
        }
        return $s;
    }
}
