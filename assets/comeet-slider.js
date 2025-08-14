(function(){
  const WRAP_ID = 'careers';

  function waitForAny(selector, root=document, timeout=20000){
    return new Promise((resolve, reject)=>{
      try {
        const found = root.querySelectorAll(selector);
        if (found && found.length) return resolve(found);
      } catch(e){}
      const to = setTimeout(()=>{ try{obs.disconnect();}catch(e){}; reject('timeout'); }, timeout);
      const obs = new MutationObserver(()=>{
        try {
          const list = root.querySelectorAll(selector);
          if (list && list.length){ clearTimeout(to); obs.disconnect(); resolve(list); }
        } catch(e){}
      });
      obs.observe(root, {childList:true, subtree:true});
    });
  }

  function q(sel, scope){ try { return (scope||document).querySelector(sel); } catch(e){ return null; } }
  function hasGoodHref(a){
    if (!a) return false;
    const href = (a.getAttribute('href') || a.getAttribute('data-url') || a.getAttribute('data-href') || '').trim();
    if (!href || href === '#' || href.startsWith('javascript')) return false;
    return true;
  }
  function absoluteUrl(url){
    try { return new URL(url, window.location.origin).toString(); } catch(e){ return null; }
  }
  function triggerSequence(el){
    if (!el) return;
    ['mousedown','mouseup','click'].forEach(type=>{
      try { el.dispatchEvent(new MouseEvent(type, {bubbles:true, cancelable:true, view:window})); } catch(e){}
    });
  }

  function buildSlideFromItem(item, originalAnchor){
    const titleEl = q('a, .title, [data-title], h3, h2', item) || item;
    const title = (titleEl.textContent||'').replace(/\s{2,}/g,' ').trim();
    const metaEl  = q('.comeet-position-details, .details, .subtitle, .meta', item);
    const meta = metaEl ? metaEl.textContent.trim() : '';

    let href = null;
    if (hasGoodHref(originalAnchor)) {
      const raw = originalAnchor.getAttribute('href') || originalAnchor.getAttribute('data-url') || originalAnchor.getAttribute('data-href');
      href = absoluteUrl(raw);
    }

    const slide = document.createElement('div');
    slide.className = 'swiper-slide';
    slide.innerHTML = `
      <article class="job-card">
        <h3 class="job-title">${title}</h3>
        ${meta ? `<div class="job-meta">${meta}</div>` : ''}
        <div class="job-cta"><a ${href ? `href="${href}" target="_blank" rel="noopener"` : 'href="#"'}>לפרטים והגשת מועמדות</a></div>
      </article>`;

    const cta = slide.querySelector('.job-cta a');
    cta.addEventListener('click', function(ev){
      if (!href){
        ev.preventDefault();
        const a = originalAnchor || q('a', item);
        if (a) return triggerSequence(a);
        triggerSequence(item);
      }
    });
    return slide;
  }

  function initSliderFromRoots(host, roots){
    const pairs = [];
    roots.forEach(root => {
      let list = root.querySelectorAll('.comeet-position, .comeet-position-item, .comeet-list-item, .position');
      if (!list.length) list = root.querySelectorAll('li');
      list.forEach(it => pairs.push({item: it, anchor: q('a[href], [data-url], [data-href]', it)}));
    });
    if (!pairs.length){ console.warn('[Comeet Slider] No job items found'); return; }

    const swiperEl = document.createElement('div');
    swiperEl.className = 'swiper';
    const wrap = document.createElement('div');
    wrap.className = 'swiper-wrapper';
    swiperEl.appendChild(wrap);

    pairs.forEach(({item, anchor}) => wrap.appendChild(buildSlideFromItem(item, anchor)));

    const nav = document.createElement('div');
    nav.className = 'cr-jobs-nav';
    nav.innerHTML = `
      <button class="cr-jobs-button prev" aria-label="הקודם" type="button"></button>
      <button class="cr-jobs-button next" aria-label="הבא" type="button"></button>
    `;

    host.insertBefore(swiperEl, host.firstChild);
    host.insertBefore(nav, swiperEl.nextSibling);

    // Hide originals inside their roots
    roots.forEach(root => { root.style.display = 'none'; });

    if (typeof Swiper === 'undefined') { console.warn('Swiper missing'); return; }
    const swiper = new Swiper(swiperEl, {
      rtl: document.documentElement.dir === 'rtl',
      slidesPerView: 1.15,
      spaceBetween: 14,
      grabCursor: true,
      keyboard: { enabled: true },
      navigation: { nextEl: nav.querySelector('.next'), prevEl: nav.querySelector('.prev') },
      breakpoints: {
        480:{slidesPerView:1.25,spaceBetween:18},
        768:{slidesPerView:2.2, spaceBetween:18},
        1024:{slidesPerView:3,  spaceBetween:20},
        1366:{slidesPerView:3.5,spaceBetween:22}
      }
    });
  }

  async function boot(){
    const host = document.getElementById(WRAP_ID);
    if (!host) return;
    const selector = [
      '#'+WRAP_ID+' .comeet-positions',
      '#'+WRAP_ID+' .comeet-widget',
      '#'+WRAP_ID+' .comeet-container',
      '#'+WRAP_ID+' .comeet',
      '#'+WRAP_ID+' ul',
      '#'+WRAP_ID+' ol'
    ].join(', ');
    try {
      const roots = await waitForAny(selector, host, 20000);
      setTimeout(()=> initSliderFromRoots(host, roots), 200);
    } catch(e){
      console.warn('[Comeet Slider] timeout – selector didn’t match', e);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();