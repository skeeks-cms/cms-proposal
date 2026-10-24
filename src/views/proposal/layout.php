<?php
/**
 * The own layout of the client page of a proposal: light only, not indexed.
 *
 * It deliberately calls none of beginPage()/head()/beginBody()/endBody()/
 * endPage(): site packages hook those view events (theme scripts, cookie
 * banner, counters, generated keywords), and none of that belongs on a
 * personal page of a client. The page has only its own style and script.
 *
 * @var yii\web\View $this
 * @var string $content
 */

use skeeks\cms\proposal\assets\ProposalPageAsset;
use yii\helpers\Html;

$assets = \Yii::$app->assetManager;
$bundle = $assets->getBundle(ProposalPageAsset::class);
$asset = static function (string $file) use ($bundle, $assets) {
    $path = $bundle->basePath.'/'.$file;

    return $bundle->baseUrl.'/'.$file.(is_file($path) ? '?v='.filemtime($path) : '');
};
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <?= Html::csrfMetaTags(); ?>
    <title><?= Html::encode($this->title); ?></title>
    <link rel="stylesheet" href="<?= Html::encode($asset('proposal-page.css')); ?>">
    <?php if (!empty($this->params['kpStyle'])) : ?><style><?= $this->params['kpStyle']; ?></style><?php endif; ?>
</head>
<body class="sx-kp-body">
<?= $content; ?>
<?php // The shared "a person saw the page" signal of skeeks/cms-communication, then the page script. ?>
<script src="<?= Html::encode(\skeeks\cms\communication\assets\LinkSignalAsset::scriptUrl()); ?>"></script>
<script src="<?= Html::encode($asset('proposal-page.js')); ?>"></script>
<?php if (!empty($this->params['kpScript'])) : ?><script><?= $this->params['kpScript']; ?></script><?php endif; ?>
</body>
</html>
