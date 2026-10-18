// статус ниспадающего меню
            var menustat = false,
            postavkamenustat = true;

            // само меню
            var ddmenu = document.getElementById("ddmenu");
            var bddmenu = document.getElementById("bddmenu");
            var selectedElement = document.getElementById('topddmenu');
            var postavkamenu = document.getElementById('postavkam');
            var postavkablock = document.getElementById('postavka');
            
            // ===========
            // обрабатываю нажатие меню в полноэкранном режиме
            // ===========
            // обрабатываем касание в полноэкранном меню, т.к. оно может отображаться на планшетах
                        // обрабатываем клик в полноэкранном меню по Поставке
            function postavkaclickfull() {
                // проверяем статус меню. Если оно выключено, то включаем. Иначе - наоборот.
                    if (menustat == false) {
                        ddmenu.style.display = "block";
                        menustat = true;
                    } else {
                        ddmenu.style.display = "none";
                        menustat = false;
                    }
            };


            // ===========
            // обрабатываю нажатие меню в мобильной версии
            // ===========
                        function postavkaclick() {
                // проверяем статус меню. Если оно выключено, то включаем. Иначе - наоборот.
                if (postavkamenustat == false) {
                    postavkablock.style.display = "flex";
                    postavkamenustat = true;
                } else {
                    postavkablock.style.display = "none";
                    postavkamenustat = false;
                }
            };

// ===== ABV: compact glass header (appended; remove this block to disable) =====
(function () {
    function init() {
        var src = document.querySelector('section[data-pgc="headmenu"]');
        if (!src || document.querySelector('.abv-glassbar')) return;
        var bar = document.createElement('div');
        bar.className = 'abv-glassbar';
        bar.setAttribute('aria-hidden', 'true');
        var inner = document.createElement('div');
        inner.className = 'gb-in';

        var logo = src.querySelector('.logo-block');
        if (logo) {
            var l = logo.cloneNode(true);
            l.className = 'gb-logo';
            l.removeAttribute('id');
            inner.appendChild(l);
        }
        var menu = src.querySelector('.items-in-line .menu-items');
        if (menu) {
            var nav = document.createElement('div');
            nav.className = 'gb-nav';
            var m = menu.cloneNode(true);
            var withId = m.querySelectorAll('[id]');
            for (var i = 0; i < withId.length; i++) withId[i].removeAttribute('id');
            var clicks = m.querySelectorAll('[onclick]');
            for (var j = 0; j < clicks.length; j++) clicks[j].removeAttribute('onclick');
            var dd = m.querySelectorAll('.dropdown > .main-menu');
            for (var k = 0; k < dd.length; k++) dd[k].setAttribute('tabindex', '0');
            nav.appendChild(m);
            inner.appendChild(nav);
        }
        var tel = src.querySelector('a[href^="tel:"]');
        if (tel) {
            var ph = document.createElement('a');
            ph.className = 'gb-ph';
            ph.href = tel.getAttribute('href');
            ph.textContent = (tel.textContent || '').replace(/\s+/g, ' ').trim();
            inner.appendChild(ph);
        }
        var up = document.createElement('button');
        up.type = 'button';
        up.className = 'gb-up';
        up.setAttribute('aria-label', 'Top');
        up.innerHTML = '&#8593;';
        up.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
        inner.appendChild(up);

        var prog = document.createElement('span');
        prog.className = 'gb-prog';
        bar.appendChild(inner);
        bar.appendChild(prog);
        document.body.appendChild(bar);

        function onScroll() {
            var r = src.getBoundingClientRect();
            var show = r.bottom < 0;
            bar.classList.toggle('on', show);
            bar.setAttribute('aria-hidden', show ? 'false' : 'true');
            var max = document.documentElement.scrollHeight - window.innerHeight;
            bar.style.setProperty('--p', max > 0 ? Math.min(1, window.scrollY / max) : 0);
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        onScroll();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

// ===== ABV: compact mobile header + collapsed "Postavka" (appended; remove this block to disable) =====
(function () {
    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html) e.innerHTML = html; return e; }
    var A = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
    var IC = {
        burger: '<svg viewBox="0 0 24 24" ' + A + '><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
        x: '<svg viewBox="0 0 24 24" ' + A + '><path d="M6 6l12 12M18 6L6 18"/></svg>',
        search: '<svg viewBox="0 0 24 24" ' + A + '><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>',
        tg: '<svg viewBox="0 0 24 24" ' + A + '><path d="M21 4L3 11l6 2 2 6 3-4 5 4z"/><path d="M9 13l12-9"/></svg>',
        phone: '<svg viewBox="0 0 24 24" ' + A + '><path d="M6.6 10.8c1.5 3 3.9 5.4 6.9 6.9l2.3-2.3c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.5.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.2c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.5.1.4 0 .8-.3 1z"/></svg>'
    };
    var T = { menu: '\u041c\u0435\u043d\u044e', close: '\u0417\u0430\u043a\u0440\u044b\u0442\u044c', find: '\u041f\u043e\u0438\u0441\u043a \u043f\u043e \u0441\u043a\u043b\u0430\u0434\u0443',
              ph: '\u0410\u0440\u0442\u0438\u043a\u0443\u043b \u0438\u043b\u0438 \u043d\u0430\u0437\u0432\u0430\u043d\u0438\u0435', go: '\u041d\u0410\u0419\u0422\u0418', call: '\u041f\u043e\u0437\u0432\u043e\u043d\u0438\u0442\u044c' };

    function collapsePostavka() {
        var b = document.getElementById('postavka');
        if (b) b.style.display = 'none';
        try { postavkamenustat = false; } catch (e) {}
    }

    function init() {
        collapsePostavka();
        var src = document.querySelector('section[data-pgc="headmenu"]');
        if (!src || document.querySelector('.abv-mbar')) return;
        var body = document.body;
        var logoImg = src.querySelector('.logo-block img');
        var logoA = src.querySelector('.logo-block');
        var tel = src.querySelector('a[href^="tel:"]');
        var tg = src.querySelector('a[href*="t.me/"]');

        var bar = el('div', 'abv-mbar');
        var row = el('div', 'mb-in');
        var bBurger = el('button', 'mb-ic mb-burger', IC.burger); bBurger.type = 'button'; bBurger.setAttribute('aria-label', T.menu);
        var bX = el('button', 'mb-ic mb-x', IC.x); bX.type = 'button'; bX.setAttribute('aria-label', T.close);
        var bFind = el('button', 'mb-ic mb-find', IC.search); bFind.type = 'button'; bFind.setAttribute('aria-label', T.find);
        var logo = el('a', 'mb-logo'); logo.href = logoA ? logoA.getAttribute('href') : 'index.html';
        if (logoImg) { var li = el('img'); li.src = logoImg.getAttribute('src'); li.alt = logoImg.getAttribute('alt') || ''; logo.appendChild(li); }
        var aTg = el('a', 'mb-ic', IC.tg); aTg.href = tg ? tg.getAttribute('href') : 'https://t.me/ABV_es_bot'; aTg.target = '_blank'; aTg.rel = 'noopener'; aTg.setAttribute('aria-label', 'Telegram');
        var aTel = el('a', 'mb-ic', IC.phone); aTel.href = tel ? tel.getAttribute('href') : 'tel:88002503392'; aTel.setAttribute('aria-label', T.call);
        [bBurger, bX, bFind, logo, aTg, aTel].forEach(function (n) { row.appendChild(n); });

        var sWrap = el('div', 'mb-search');
        var form = el('form'); form.setAttribute('role', 'search');
        var inp = el('input'); inp.type = 'search'; inp.placeholder = T.ph; inp.setAttribute('aria-label', T.find); inp.setAttribute('enterkeyhint', 'search');
        var sub = el('button', '', T.go); sub.type = 'submit';
        form.appendChild(inp); form.appendChild(sub); sWrap.appendChild(form);
        var prog = el('span', 'mb-prog'); prog.setAttribute('aria-hidden', 'true');
        bar.appendChild(row); bar.appendChild(sWrap); bar.appendChild(prog);
        body.insertBefore(bar, body.firstChild);

        function closeMenu() { body.classList.remove('abv-menu-open'); }
        bBurger.addEventListener('click', function () { bar.classList.remove('searching'); collapsePostavka(); body.classList.add('abv-menu-open'); });
        bX.addEventListener('click', closeMenu);
        src.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('a[href]'); if (a) closeMenu(); });
        bFind.addEventListener('click', function () {
            closeMenu(); bar.classList.toggle('searching');
            if (bar.classList.contains('searching')) setTimeout(function () { inp.focus(); }, 30);
        });
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var v = inp.value.trim(), own = document.querySelector('.abv-catalog .abv-search');
            if (own && document.querySelector('.abv-cards')) {
                own.value = v; own.dispatchEvent(new Event('input')); bar.classList.remove('searching'); inp.blur();
                window.scrollTo({ top: own.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
            } else {
                location.href = 'sklad.html' + (v ? '?q=' + encodeURIComponent(v) : '');
            }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenu(); bar.classList.remove('searching'); } });
        function onScroll() {
            bar.classList.toggle('glass', window.scrollY > 4);
            var m = document.documentElement.scrollHeight - window.innerHeight;
            bar.style.setProperty('--p', m > 0 ? Math.min(1, window.scrollY / m) : 0);
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

// ===== ABV: smooth inertial scroll on desktop (appended; remove this block to disable) =====
(function () {
    var fine = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!fine || reduce || window.__abvSmooth) return;
    window.__abvSmooth = true;

    var root = document.documentElement;
    var target = window.scrollY, cur = window.scrollY, running = false, ease = 0.055;

    function maxScroll() { return Math.max(0, root.scrollHeight - window.innerHeight); }
    function locked() {
        var b = document.body;
        if (!b) return true;
        var bs = getComputedStyle(b), rs = getComputedStyle(root);
        if (bs.overflow === 'hidden' || bs.overflowY === 'hidden' || rs.overflowY === 'hidden') return true;
        if (b.classList.contains('abv-menu-open')) return true;
        var ov = document.getElementById('rf-overlay');
        if (ov && ov.classList.contains('rf-open')) return true;
        return false;
    }
    function innerScrollable(el, dy) {
        while (el && el !== document.body && el !== root) {
            if (el.nodeType === 1) {
                var cs = getComputedStyle(el), oy = cs.overflowY;
                if ((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight + 1) {
                    if (dy > 0 && el.scrollTop + el.clientHeight < el.scrollHeight - 1) return true;
                    if (dy < 0 && el.scrollTop > 0) return true;
                }
            }
            el = el.parentNode;
        }
        return false;
    }
    function tick() {
        cur += (target - cur) * ease;
        if (Math.abs(target - cur) < 0.5) { cur = target; running = false; }
        window.scrollTo(0, cur);
        if (running) requestAnimationFrame(tick);
    }
    window.addEventListener('wheel', function (e) {
        if (e.defaultPrevented || e.ctrlKey || e.metaKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;
        if (locked() || innerScrollable(e.target, e.deltaY)) return;
        e.preventDefault();
        var d = e.deltaY * (e.deltaMode === 1 ? 40 : e.deltaMode === 2 ? window.innerHeight : 1) * 1.15;
        if (!running) { cur = window.scrollY; target = cur; }
        target = Math.max(0, Math.min(maxScroll(), target + d));
        if (!running) { running = true; requestAnimationFrame(tick); }
    }, { passive: false });
    // keyboard, scrollbar, anchors, scripts: keep in sync, never fight them
    window.addEventListener('scroll', function () { if (!running) { cur = target = window.scrollY; } }, { passive: true });
    ['keydown', 'mousedown', 'touchstart'].forEach(function (ev) {
        window.addEventListener(ev, function () { running = false; cur = target = window.scrollY; }, { passive: true });
    });
    window.addEventListener('resize', function () { target = Math.min(target, maxScroll()); });
    try { root.style.scrollBehavior = 'auto'; } catch (e) {}
})();

// ===== ABV: mobile helpers (appended; remove this block to disable) =====
(function () {
    if (!window.matchMedia || !matchMedia('(max-width: 890px)').matches) return;
    var MORE = '\u041f\u043e\u0434\u0440\u043e\u0431\u043d\u0435\u0435 \u25be', LESS = '\u0421\u0432\u0435\u0440\u043d\u0443\u0442\u044c \u25b4';
    function clamp(p, cls) {
        p.classList.add('abv-clamp'); if (cls) p.classList.add(cls);
        if (p.scrollHeight <= p.clientHeight + 4) { p.classList.remove('abv-clamp'); return; }
        var b = document.createElement('button'); b.type = 'button'; b.className = 'abv-more'; b.textContent = MORE;
        b.addEventListener('click', function () { var open = p.classList.toggle('abv-clamp'); b.textContent = open ? MORE : LESS; });
        p.parentNode.insertBefore(b, p.nextSibling);
    }
    function init() {
        // auto-ribbon (slider.js) is heavy on phones: stop it, the strip is swiped by finger instead
        if (document.getElementById('ihost')) { window.onload = null; }
        var ps = document.querySelectorAll('body > section:not([data-pgc]) p');
        for (var i = 0; i < ps.length; i++) {
            var p = ps[i], t = (p.textContent || '').trim().length;
            if (p.closest('footer, .abvx, .abv-catalog, .abv-product, #rf-overlay, .abv-mbar')) continue;
            if (t > 140 && (p.style.textAlign === 'center' || getComputedStyle(p).textAlign === 'center')) p.classList.add('abv-left');
            if (p.classList.contains('project-desc')) { if (t > 160) clamp(p, 'abv-c3'); continue; }
            if (t > 420) clamp(p);
        }
        var fr = document.querySelectorAll('iframe'); for (var j = 0; j < fr.length; j++) fr[j].setAttribute('loading', 'lazy');
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
