/**
 * The client page of a proposal and the transition page of a presentation.
 *
 * The view is counted by this script only: after the page has been
 * visible for a moment (a robot that only fetches the link never sends
 * it). Then "Принять" with a confirmation and the "Задать вопрос" form;
 * a presentation leads on to its page after the signal.
 */
(function () {
    'use strict';

    /** Visible this long before it counts, ms. */
    var SEEN_DELAY = 1500;

    function csrf() {
        var param = document.querySelector('meta[name="csrf-param"]');
        var token = document.querySelector('meta[name="csrf-token"]');
        return param && token ? {param: param.content, token: token.content} : null;
    }

    function post(url, data) {
        var body = new FormData();
        var c = csrf();
        if (c) {
            body.append(c.param, c.token);
        }
        Object.keys(data || {}).forEach(function (key) {
            body.append(key, data[key]);
        });
        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            keepalive: true
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('http');
            }
            return response.json();
        });
    }

    /** The shared signal of skeeks/cms-communication (link-signal.js): once the page was visible for a moment. */
    function seen(url) {
        window.sxLinkSignal.whenSeen(SEEN_DELAY, function () {
            return post(url, browserSignals());
        });
    }

    function showError(el, text) {
        if (!el) {
            return;
        }
        el.textContent = text || '';
        el.hidden = !text;
    }

    function accept(url) {
        var button = document.querySelector('[data-sx-kp-accept]');
        var dialog = document.querySelector('[data-sx-kp-accept-dialog]');
        if (!button || !dialog) {
            return;
        }
        var confirm = dialog.querySelector('[data-sx-kp-accept-confirm]');
        var error = dialog.querySelector('[data-sx-kp-accept-error]');

        button.addEventListener('click', function () {
            showError(error, '');
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else if (window.confirm('Принять предложение?')) {
                confirm.click();
            }
        });
        confirm.addEventListener('click', function () {
            confirm.disabled = true;
            post(url, {}).then(function (response) {
                if (!response.success) {
                    showError(error, response.message);
                    confirm.disabled = false;
                    return;
                }
                if (dialog.open) {
                    dialog.close();
                }
                button.remove();
                var done = document.querySelector('[data-sx-kp-accepted-now]');
                if (done) {
                    done.textContent = response.message;
                    done.hidden = false;
                    done.scrollIntoView({block: 'center', behavior: 'smooth'});
                }
                var ask = document.querySelector('[data-sx-kp-ask-open]');
                if (ask) {
                    ask.classList.add('sx-kp-button--primary');
                }
            }).catch(function () {
                showError(error, 'Не получилось отправить. Проверьте интернет и попробуйте ещё раз.');
                confirm.disabled = false;
            });
        });
    }

    function ask(url) {
        var open = document.querySelector('[data-sx-kp-ask-open]');
        var block = document.querySelector('[data-sx-kp-ask]');
        if (!open || !block) {
            return;
        }
        var form = block.querySelector('[data-sx-kp-ask-form]');
        var error = block.querySelector('[data-sx-kp-ask-error]');
        var done = block.querySelector('[data-sx-kp-ask-done]');

        open.addEventListener('click', function () {
            block.hidden = false;
            form.hidden = false;
            done.hidden = true;
            block.scrollIntoView({block: 'start', behavior: 'smooth'});
            form.elements.text.focus({preventScroll: true});
        });
        block.querySelector('[data-sx-kp-ask-close]').addEventListener('click', function () {
            block.hidden = true;
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = form.elements.text.value.trim();
            if (text.length < 3) {
                showError(error, 'Напишите вопрос.');
                form.elements.text.focus();
                return;
            }
            var submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            showError(error, '');
            post(url, {
                text: text,
                name: form.elements.name.value,
                contact: form.elements.contact.value,
                website: form.elements.website.value
            }).then(function (response) {
                submit.disabled = false;
                if (!response.success) {
                    showError(error, response.message);
                    return;
                }
                form.reset();
                form.hidden = true;
                done.textContent = response.message;
                done.hidden = false;
            }).catch(function () {
                submit.disabled = false;
                showError(error, 'Не получилось отправить. Проверьте интернет и попробуйте ещё раз.');
            });
        });
    }

    window.sxProposalPage = function (urls) {
        seen(urls.seen);
        accept(urls.accept);
        ask(urls.ask);
    };

    /** What a link check in an automated browser gives away (LinkChecks of skeeks/cms-communication). */
    function browserSignals() {
        return window.sxLinkSignal.browserSignals();
    }

    /** Visible this long before a presentation counts and leads on, ms. */
    var GO_DELAY = 400;
    /** The transition waits for the view signal no longer than this, ms. */
    var GO_TIMEOUT = 2500;
    /** An employee reads the notice before the transition, ms. */
    var STAFF_DELAY = 3000;

    /**
     * The transition page of a presentation: the view signal first, then the
     * page of the presentation in place of this one (Back does not return
     * here). Without scripts nothing happens: the link "Перейти" stays.
     */
    window.sxPresentationPage = function (options) {
        var status = document.querySelector('[data-sx-kp-go-status]');
        var spinner = document.querySelector('[data-sx-kp-go-spinner]');
        var started = false;
        var timer = null;

        function go(delay) {
            setTimeout(function () {
                window.location.replace(options.target);
            }, delay || 0);
        }

        function start() {
            if (started) {
                return;
            }
            started = true;
            if (status) {
                status.textContent = 'Открываем страницу…';
            }
            if (spinner) {
                spinner.hidden = false;
            }
            var timeout = new Promise(function (resolve) {
                setTimeout(function () {
                    resolve({});
                }, GO_TIMEOUT);
            });
            Promise.race([post(options.seen, browserSignals()), timeout]).then(function (response) {
                if (options.staff || (response && response.result === 'staff')) {
                    if (status) {
                        status.textContent = 'Переход не засчитан: вы вошли как сотрудник. Открываем страницу…';
                    }
                    go(STAFF_DELAY);
                    return;
                }
                go(0);
            }).catch(function () {
                go(0);
            });
        }

        function arm() {
            if (started || timer || document.visibilityState !== 'visible') {
                return;
            }
            timer = setTimeout(function () {
                timer = null;
                if (document.visibilityState === 'visible') {
                    start();
                }
            }, GO_DELAY);
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                arm();
            } else if (timer) {
                clearTimeout(timer);
                timer = null;
            }
        });
        arm();
    };
})();
