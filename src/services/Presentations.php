<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;
use skeeks\cms\proposal\models\CmsProposal;

/**
 * Presentations created and edited outside the "Предложения" form (API, AI
 * clients): the same model, rules and visibility as the form. A presentation
 * for everybody has no client; one for a client has exactly one (a lead, a
 * company, a deal or a client person) the employee sees. Before creating, a
 * presentation of the same page for the same client (or for everybody) is
 * reported as a duplicate. Editing changes only the given fields; the client
 * of a presentation that was already sent stays.
 */
class Presentations
{
    public const CLIENT_TYPES = ['lead' => CmsLead::class, 'company' => CmsCompany::class, 'deal' => CmsDeal::class, 'client' => CmsUser::class];
    public const CLIENT_ATTRIBUTES = ['lead' => 'cms_lead_id', 'company' => 'cms_company_id', 'deal' => 'cms_deal_id', 'client' => 'cms_user_id'];

    private CmsUser $user;
    private int $siteId;

    public function __construct(User $user, ?int $siteId = null)
    {
        $this->user = $user instanceof CmsUser ? $user : CmsUser::findOne((int)$user->id);
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
    }

    /** A presentation the employee sees, or null. */
    public function find(int $id): ?CmsProposal
    {
        $t = CmsProposal::tableName();

        return CmsProposal::find()->forManager($this->user)
            ->andWhere([$t.'.id' => $id, $t.'.cms_site_id' => $this->siteId, $t.'.kind' => CmsProposal::KIND_PRESENTATION])->one();
    }

    /**
     * A new presentation, validated; saved only with $apply.
     *
     * @param array $data name, target_url, message (HTML), client {type, id} or null — for everybody,
     *                    valid_until (Y-m-d or null), responsible_id, allow_duplicate
     * @return array model (CmsProposal), duplicates (CmsProposal[]), errors (field => text)
     */
    public function create(array $data, bool $apply): array
    {
        $model = new CmsProposal(['kind' => CmsProposal::KIND_PRESENTATION, 'cms_site_id' => $this->siteId]);
        $model->responsible_id = (int)$this->user->id;
        $errors = $this->load($model, $data, true);
        if (!$errors && !$model->validate()) {
            $errors = $model->getFirstErrors();
        }
        $duplicates = $errors ? [] : $this->duplicates($model);
        if (!$errors && $duplicates && empty($data['allow_duplicate'])) {
            $errors['target_url'] = 'Презентация этой страницы '.($model->client ? 'для этого клиента' : 'для всех').' уже есть: '
                .implode(', ', array_map(static fn(CmsProposal $p) => $p->numberText.' (id '.$p->id.')', $duplicates))
                .'. Измените её (cms_proposal_presentation_update) или передайте allow_duplicate=true, если нужна ещё одна.';
        }
        if (!$errors && $apply && !$model->save(false)) {
            $errors['name'] = 'Не удалось сохранить презентацию.';
        }

        return ['model' => $model, 'duplicates' => $duplicates, 'errors' => $errors];
    }

    /**
     * Changes the given fields only; others stay as they are.
     *
     * @return array model, changed (attribute names), errors
     */
    public function update(CmsProposal $model, array $data, bool $apply): array
    {
        $errors = $this->load($model, $data, false);
        if (!$errors && !$model->validate()) {
            $errors = $model->getFirstErrors();
        }
        $changed = array_keys($model->getDirtyAttributes());
        if (!$errors && $apply && $changed && !$model->save(false)) {
            $errors['name'] = 'Не удалось сохранить презентацию.';
        }

        return ['model' => $model, 'changed' => $changed, 'errors' => $errors];
    }

    /** Presentations of the same page for the same client (or for everybody). */
    public function duplicates(CmsProposal $model): array
    {
        $t = CmsProposal::tableName();
        $query = CmsProposal::find()->andWhere([$t.'.cms_site_id' => $this->siteId, $t.'.kind' => CmsProposal::KIND_PRESENTATION])
            ->andWhere(['in', $t.'.target_url', array_unique([(string)$model->target_url, rtrim((string)$model->target_url, '/'), rtrim((string)$model->target_url, '/').'/'])]);
        foreach (self::CLIENT_ATTRIBUTES as $attribute) {
            $query->andWhere([$t.'.'.$attribute => $model->$attribute ?: null]);
        }
        if (!$model->isNewRecord) {
            $query->andWhere(['!=', $t.'.id', (int)$model->id]);
        }

        return $query->orderBy([$t.'.id' => SORT_ASC])->limit(5)->all();
    }

    /** @return array field => error; the model gets only the given values */
    private function load(CmsProposal $model, array $data, bool $insert): array
    {
        $errors = [];
        foreach (['name', 'target_url', 'message'] as $attribute) {
            if (array_key_exists($attribute, $data)) {
                $model->$attribute = $data[$attribute] === null ? null : (string)$data[$attribute];
            }
        }
        if (array_key_exists('valid_until', $data)) {
            $value = trim((string)$data['valid_until']);
            if ($value === '') {
                $model->valid_until = null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && ($time = strtotime($value.' 23:59:59')) !== false) {
                $model->valid_until = $time;
            } else {
                $errors['valid_until'] = 'Срок действия — дата ГГГГ-ММ-ДД или пусто, чтобы снять срок.';
            }
        }
        if (array_key_exists('responsible_id', $data)) {
            $id = (int)$data['responsible_id'];
            if (!$id || !CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => $id])->exists()) {
                $errors['responsible_id'] = 'Ответственный — сотрудник CRM (cms_worker_list).';
            } else {
                $model->responsible_id = $id;
            }
        }
        if (array_key_exists('client', $data)) {
            if (!$insert && $model->getSends()->exists()) {
                $errors['client'] = 'Презентацию уже отправляли: клиента у неё менять нельзя. Создайте новую.';
            } else {
                $client = $data['client'];
                foreach (self::CLIENT_ATTRIBUTES as $attribute) {
                    $model->$attribute = null;
                }
                if (is_array($client) && !empty($client['type'])) {
                    $type = (string)$client['type'];
                    $class = self::CLIENT_TYPES[$type] ?? null;
                    $record = $class ? $class::find()->forManager($this->user)->andWhere([$class::tableName().'.id' => (int)($client['id'] ?? 0)])->one() : null;
                    if (!$record || ($record instanceof CmsUser && $record->is_worker)) {
                        $errors['client'] = 'Клиент не найден или вам не виден: client {type: lead|company|deal|client, id}; без client — презентация для всех.';
                    } else {
                        $model->{self::CLIENT_ATTRIBUTES[$type]} = (int)$record->id;
                    }
                }
            }
        }

        return $errors;
    }
}
