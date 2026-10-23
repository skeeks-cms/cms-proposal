<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\assets;

use yii\web\AssetBundle;

/**
 * The client page of a proposal: its own light design and the small
 * script of the page (the view signal, "Принять", "Задать вопрос"). No
 * jQuery, nothing of the site theme or the backend.
 */
class ProposalPageAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/proposal/assets/src/page';

    public $css = ['proposal-page.css'];

    public $js = ['proposal-page.js'];
}
