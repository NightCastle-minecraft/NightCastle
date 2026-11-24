(function () {
  'use strict';

  document.documentElement.classList.add('js');

  // Бургер-меню
  var toggle = document.querySelector('[data-nav-toggle]');
  var menu = document.querySelector('[data-nav-menu]');
  if (toggle && menu) {
    var setOpen = function (open) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      menu.classList.toggle('is-open', open);
    };
    toggle.addEventListener('click', function () {
      setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        setOpen(false);
        toggle.focus();
      }
    });
  }

  // Копирование IP
  var live = document.querySelector('[data-copy-live]');

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.className = 'copy-helper';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (err) {
      ok = false;
    }
    document.body.removeChild(ta);
    return ok;
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function () {
        return true;
      }, function () {
        return fallbackCopy(text);
      });
    }
    return Promise.resolve(fallbackCopy(text));
  }

  function textFor(btn) {
    var anchor = btn.getAttribute('data-copy-anchor');
    if (anchor) {
      return window.location.origin + window.location.pathname + '#' + anchor;
    }
    return btn.getAttribute('data-copy-text') || '';
  }

  document.querySelectorAll('[data-copy-text], [data-copy-anchor]').forEach(function (btn) {
    var label = btn.querySelector('[data-copy-label]');
    var original = label ? label.textContent : '';
    var timer = null;
    btn.addEventListener('click', function () {
      copyText(textFor(btn)).then(function (ok) {
        var msg = ok ? 'Скопировано' : 'Не удалось скопировать';
        btn.classList.toggle('is-copied', ok);
        if (label) {
          label.textContent = msg;
        }
        if (live) {
          live.textContent = msg;
        }
        clearTimeout(timer);
        timer = setTimeout(function () {
          btn.classList.remove('is-copied');
          if (label) {
            label.textContent = original;
          }
          if (live) {
            live.textContent = '';
          }
        }, 2000);
      });
    });
  });

  // Статус сервера
  var chip = document.querySelector('[data-status-chip]');
  if (chip && window.fetch) {
    var textEl = chip.querySelector('[data-status-text]');
    var countEl = chip.querySelector('[data-status-count]');

    var applyStatus = function (st) {
      var state = 'unknown';
      var text = 'нет данных';
      var count = '';
      if (st && st.updatedAt) {
        if (st.online) {
          state = 'online';
          text = 'ONLINE';
          if (typeof st.players === 'number') {
            count = String(st.players);
            if (typeof st.maxPlayers === 'number') {
              count += '/' + st.maxPlayers;
            }
          }
        } else {
          state = 'offline';
          text = 'OFFLINE';
        }
      }
      chip.setAttribute('data-state', state);
      if (textEl) {
        textEl.textContent = text;
      }
      if (countEl) {
        countEl.textContent = count;
      }
    };

    var refresh = function () {
      fetch('/api/status', { headers: { Accept: 'application/json' } })
        .then(function (r) {
          return r.ok ? r.json() : null;
        })
        .then(function (st) {
          if (st) {
            applyStatus(st);
          }
        })
        .catch(function () {});
    };

    refresh();
    setInterval(refresh, 60000);
  }

  // Галерея скриншотов: слева предыдущий, по центру текущий, справа следующий.
  // Без JS остаётся горизонтальная лента с прокруткой.
  document.querySelectorAll('[data-gallery]').forEach(function (gallery) {
    var track = gallery.querySelector('[data-gallery-track]');
    var slides = Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-slide]'));
    var n = slides.length;
    if (!track || n === 0) {
      return;
    }
    var controls = gallery.querySelector('[data-gallery-controls]');
    var counter = gallery.querySelector('[data-gallery-counter]');
    var prevBtn = gallery.querySelector('[data-gallery-prev]');
    var nextBtn = gallery.querySelector('[data-gallery-next]');
    var current = 0;
    var suppressClickUntil = 0;

    function wrap(i) {
      return ((i % n) + n) % n;
    }

    function preload(i) {
      var img = slides[wrap(i)].querySelector('img');
      if (img && !img.complete) {
        var pre = new Image();
        pre.src = img.getAttribute('src');
      }
    }

    function render() {
      // При двух кадрах второй показываем только справа, иначе он был бы и слева, и справа
      var prev = n > 2 ? wrap(current - 1) : -1;
      var next = n > 1 ? wrap(current + 1) : -1;
      slides.forEach(function (slide, i) {
        var state = 'far';
        if (i === current) {
          state = 'current';
        } else if (i === prev) {
          state = 'prev';
        } else if (i === next) {
          state = 'next';
        }
        slide.setAttribute('data-state', state);
        slide.setAttribute('aria-hidden', state === 'current' ? 'false' : 'true');
        var link = slide.querySelector('a');
        if (link) {
          link.tabIndex = state === 'current' ? 0 : -1;
        }
      });
      if (counter) {
        counter.textContent = (current + 1) + ' / ' + n;
      }
      if (n > 3) {
        preload(current + 2);
        preload(current - 2);
      }
    }

    function go(i) {
      current = wrap(i);
      render();
    }

    gallery.classList.add('is-carousel');
    if (controls && n > 1) {
      controls.hidden = false;
    }
    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        go(current - 1);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        go(current + 1);
      });
    }

    // Клик по боковому кадру листает, по центральному открывает картинку целиком
    track.addEventListener('click', function (ev) {
      var slide = ev.target.closest('[data-gallery-slide]');
      if (!slide) {
        return;
      }
      if (Date.now() < suppressClickUntil) {
        ev.preventDefault();
        return;
      }
      var state = slide.getAttribute('data-state');
      if (state === 'prev') {
        ev.preventDefault();
        go(current - 1);
      } else if (state === 'next') {
        ev.preventDefault();
        go(current + 1);
      }
    });

    gallery.addEventListener('keydown', function (ev) {
      if (n < 2) {
        return;
      }
      if (ev.key === 'ArrowLeft') {
        ev.preventDefault();
        go(current - 1);
      } else if (ev.key === 'ArrowRight') {
        ev.preventDefault();
        go(current + 1);
      }
    });

    // Свайп пальцем или перетаскивание мышью
    var startX = null;
    var startY = 0;
    track.addEventListener('dragstart', function (ev) {
      ev.preventDefault();
    });
    track.addEventListener('pointerdown', function (ev) {
      if (ev.pointerType === 'mouse' && ev.button !== 0) {
        return;
      }
      startX = ev.clientX;
      startY = ev.clientY;
    });
    track.addEventListener('pointercancel', function () {
      startX = null;
    });
    track.addEventListener('pointerup', function (ev) {
      if (startX === null) {
        return;
      }
      var dx = ev.clientX - startX;
      var dy = ev.clientY - startY;
      startX = null;
      if (n > 1 && Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
        suppressClickUntil = Date.now() + 400;
        go(dx < 0 ? current + 1 : current - 1);
      }
    });

    render();
    if (window.requestAnimationFrame) {
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          gallery.classList.add('is-animated');
        });
      });
    }
  });
})();
