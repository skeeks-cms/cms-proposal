<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\mcp;

use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\tools\CallbackTool;
use skeeks\cms\mcp\tools\McpToolProviderInterface;
use skeeks\cms\proposal\services\Presentations;
use yii\base\Component;

/**
 * MCP/REST tools that create and edit presentations (scope
 * cms.proposal.write): thin adapters over services\Presentations, the same
 * model and visibility as the "Предложения" form. Saving needs
 * confirm=true; without it the result says what would be saved. Registered
 * through Yii::$app->params['cmsMcp']['toolProviders'].
 */
class ProposalActionToolProvider extends Component implements McpToolProviderInterface
{
    public function getTools(): array
    {
        $fields = [
            'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Название для сотрудников и в письме.'],
            'target_url' => ['type' => 'string', 'description' => 'Страница: https://… или путь сайта (/solutions/plitka).'],
            'message' => ['type' => ['string', 'null'], 'description' => 'Вводный текст для клиента на странице-переходе и в «Отправить клиенту» (HTML: <p>, <b>, <i>, <ul>, <a>).'],
            'client' => ['type' => ['object', 'null'], 'description' => 'Клиент: {"type":"lead|company|deal|client","id":N}. Без него — общая Презентация для всех.',
                'properties' => ['type' => ['type' => 'string', 'enum' => ['lead', 'company', 'deal', 'client']], 'id' => ['type' => 'integer']]],
            'valid_until' => ['type' => ['string', 'null'], 'description' => 'Срок действия ГГГГ-ММ-ДД; пусто — без срока.'],
            'responsible_id' => ['type' => 'integer', 'description' => 'Ответственный сотрудник; по умолчанию вы.'],
            'confirm' => ['type' => 'boolean', 'description' => 'true — сохранить. Без него — проверка и что будет сохранено.'],
        ];

        return [
            new CallbackTool([
                'name' => 'cms_proposal_presentation_create',
                'description' => 'Создать Презентацию — страницу сайта, которую клиент открывает по личной ссылке из письма (в CRM видно открытие). '
                    .'Без client — общая Презентация для всех клиентов: появляется в cms_proposal_library_get и прикладывается к письмам (offer "proposal:<id>"), '
                    .'у каждой отправки своя ссылка. Проверяет повтор: Презентация этой же страницы для того же клиента (или для всех) — отказ с её id; '
                    .'allow_duplicate=true — создать всё равно. Возвращает id, номер, состояние и ссылку на карточку. '
                    .'Пример: {"name":"Сайт для магазина плитки","target_url":"https://skeeks.com/solutions/plitka","message":"<p>Готовое решение…</p>","confirm":true}.',
                'requiredScope' => 'cms.proposal.write',
                'callback' => [$this, 'create'],
                'inputSchema' => ['type' => 'object', 'additionalProperties' => true, 'required' => ['name', 'target_url'],
                    'properties' => $fields + ['allow_duplicate' => ['type' => 'boolean']]],
            ]),
            new CallbackTool([
                'name' => 'cms_proposal_presentation_update',
                'description' => 'Изменить Презентацию: только переданные поля (name, target_url, message, valid_until — null снимает срок, responsible_id, client), '
                    .'остальные остаются. Клиента у уже отправленной Презентации менять нельзя. Изменение общей Презентации меняет её страницу и текст для всех '
                    .'будущих и уже отправленных ссылок, но не письма — тексты писем у каждого клиента свои. Пример: {"id":1,"valid_until":"2026-12-31","confirm":true}.',
                'requiredScope' => 'cms.proposal.write',
                'callback' => [$this, 'update'],
                'inputSchema' => ['type' => 'object', 'additionalProperties' => true, 'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer']] + $fields],
            ]),
        ];
    }

    public function create(array $a): array
    {
        $apply = self::confirmed($a);
        $result = $this->service()->create($a, $apply);

        return $this->result($result['model'], $result['errors'], $apply, [
            'duplicates' => array_map(fn($p) => ['id' => (int)$p->id, 'number_text' => $p->numberText, 'name' => (string)$p->name], $result['duplicates']),
        ]);
    }

    public function update(array $a): array
    {
        $model = $this->service()->find((int)($a['id'] ?? 0));
        if (!$model) {
            throw new \DomainException('Презентация не найдена или вам не видна.');
        }
        $apply = self::confirmed($a);
        $result = $this->service()->update($model, $a, $apply);

        return $this->result($model, $result['errors'], $apply, ['changed' => $result['changed']]);
    }

    private function result($model, array $errors, bool $apply, array $extra): array
    {
        if ($errors) {
            return array_merge(['success' => false, 'saved' => false, 'errors' => $errors, 'message' => implode(' ', $errors)], $extra);
        }
        if ($apply && $model->id) {
            $model->refresh();
        }
        $data = $model->id ? (new ProposalReadService())->proposalData($model, new CrmReadContext())
            : ['kind' => 'presentation', 'name' => (string)$model->name, 'target_url' => (string)$model->target_url, 'is_shared' => $model->isShared];

        return array_merge([
            'success' => true,
            'saved' => $apply,
            'requires_confirmation' => !$apply,
            'presentation' => $data,
        ], $extra);
    }

    private function service(): Presentations
    {
        $user = \Yii::$app->user->identity;
        if (!$user) {
            throw new \DomainException('Нужен пользователь CRM из OAuth-входа.');
        }

        return new Presentations($user);
    }

    private static function confirmed(array $a): bool
    {
        return ($a['confirm'] ?? false) === true || ($a['confirm'] ?? null) === 'true' || ($a['confirm'] ?? null) === 1;
    }
}
