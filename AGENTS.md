# CMS Proposal development

Read README.md and `skeeks/cms-communication/ARCHITECTURE.md` ("КП (реестр
предложений)", "Отправить клиенту") before changing this package. Follow the
active shared-vendor skeeks-cms-package-development skill
(`references/crm-card-blocks.md` describes the card block, the record source
and the versioned document contracts this package established).

This package owns the one registry of proposals of two kinds: a commercial
proposal (КП) and a presentation (a page of a site behind a personal link,
see README "Презентация"). It owns the proposal, its versions, items
with sections, templates, sendings with personal link codes, page views,
the "КП" section, the "КП" block of lead, company and deal cards and the
PDF. CMS core owns leads, companies, deals, the feed and the extension
points; never make core depend on this package. Sending goes through the
common "Отправить клиенту" of skeeks/cms-communication; do not add a mailer
or an SMS sender here.

Content is written only by `services\ProposalVersions` (the CRM tab, API
tools and bots alike). A version with `sent_at` never changes: the models
refuse it, the service makes a new version. Feed entries are written by
`services\ProposalFeed`, never by views.

A presentation has no versions, items, PDF or "Принять": check
`CmsProposal::$isPresentation` wherever a version is read. Its target page is
read only from the proposal (never from a link) and gets UTM marks through
`services\PresentationLinks`. The first counted view of a sending (both
kinds) runs `services\ProposalTasks` (the call task rule, switched off by
`components\ProposalSettings`); link checks are recognised by
skeeks/cms-communication `services\LinkChecks` and never give status,
notification or task.

Visibility is `CmsProposalQuery::forManager()`: it reads `Yii::$app->user`
for the current employee; pass the employee explicitly in jobs.

Sendings are written only by `services\ProposalSends` (through
`send\ProposalSendable`), views by `services\ProposalViews`, client answers
by `services\ProposalAnswers`. The client page (`controllers\ProposalController`,
`/~kp/<code>`) has no sign-in and no site page events: keep its own layout,
never add counters or theme assets to it, and never count a view by opening
the link (see the skill's `references/client-link-pages.md`).

`skeeks/cms-shop` is optional: items keep `shop_product_id` without a
foreign key and the product search is off without the package.

Run `tests/proposal.php` (it also runs `tests/client.php` and
`tests/presentation.php`) and PHP syntax checks. No external sends, release,
commit, push or production deployment are implied by a local code change.
