<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\assets;

use yii\web\AssetBundle;

/**
 * The items editor of proposals and templates. Registered only by forms
 * with the editor; colors come from the shared --sx-* theme.
 */
class ProposalItemsAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/proposal/assets/src';

    public $css = ['proposal-items.css'];

    public $js = ['proposal-items.js'];

    public $depends = [
        \yii\web\JqueryAsset::class,
        \skeeks\cms\backend\widgets\sortable\assets\BackendSortableJsAsset::class,
    ];
}
