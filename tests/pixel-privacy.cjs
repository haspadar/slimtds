// Isolated browser fixture: no application, database or external network access.
// Build public/p.js first; optionally set PIXEL_CONTROL_BUNDLE to an unprotected
// build to prove the same fixture detects leaks. PIXEL_PRIVACY_RESULT saves proof.
const { chromium } = require('playwright')
const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')
const assert = require('node:assert/strict')
const bundle = fs.readFileSync(path.resolve(__dirname, '../public/p.js'), 'utf8')
const types = ['text', 'password', 'email', 'tel', 'url', 'search', 'number', 'range', 'color', 'date', 'datetime-local', 'month', 'week', 'time', 'hidden', 'checkbox', 'radio', 'button', 'submit', 'reset', 'file', 'image']
const results = []

;(async () => {
  const browser = await chromium.launch({
    ...(process.env.CHROME_BIN ? { executablePath: process.env.CHROME_BIN } : {}),
    headless: true,
  })
  try {
    const modes = ['protected', 'rate-zero', 'query-zero', 'attribute-zero']
    if (process.env.PIXEL_CONTROL_BUNDLE) modes.push('positive-control')
    for (const mode of modes) {
      const context = await browser.newContext({ serviceWorkers: 'block' })
      try {
        const page = await context.newPage()
        const posts = [], errors = [], unexpected = []
        page.on('pageerror', e => errors.push(e.message))
        await context.route('**/*', async route => {
          const req = route.request(), u = new URL(req.url())
          if (u.origin !== 'https://fixture.test') {
            unexpected.push(req.url())
            return route.abort()
          }
          if (req.method() === 'POST') {
            posts.push({ url: u.pathname, payload: JSON.parse(req.postData()) })
            return route.fulfill({ status: 204 })
          }
          if (u.pathname === '/p.js') return route.fulfill({
            contentType: 'application/javascript',
            body: mode === 'positive-control' ? fs.readFileSync(process.env.PIXEL_CONTROL_BUNDLE, 'utf8') : bundle,
          })
          if (u.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><title>Fixture</title></head><body><button id="card">VisibleCardCedar</button><div id="live"></div><div style="height:3000px"></div></body></html>' })
          unexpected.push(req.url())
          return route.abort()
        })
        await page.goto('https://fixture.test/')
        // Unique sentinels exercise attributes, initial values and mutations,
        // both in forms and in standalone controls. All are synthetic data.
        await page.evaluate(types => {
          window.secrets = []
          window.makeFields = (prefix, parent) => {
            for (const type of types) {
              const el = document.createElement('input')
              el.type = type
              const marker = `${prefix}_${type}_private`
              el.setAttribute('data-private', marker)
              if (!['file', 'number', 'range', 'color', 'date', 'datetime-local', 'month', 'week', 'time'].includes(type)) el.value = marker
              window.secrets.push(marker)
              parent.append(el)
            }
            for (const tag of ['textarea', 'select', 'div']) {
              const el = document.createElement(tag), marker = `${prefix}_${tag}_private`
              el.setAttribute('data-private', marker)
              if (tag === 'div') el.contentEditable = 'true'
              if (tag === 'select') el.innerHTML = `<option value="${marker}">${marker}</option>`
              else el.textContent = marker
              window.secrets.push(marker)
              parent.append(el)
            }
          }
          const form = document.createElement('form')
          form.setAttribute('action', '/BeforeFormPrivate')
          form.setAttribute('data-private', 'BeforeFormPrivate')
          window.secrets.push('BeforeFormPrivate')
          window.makeFields('beforeForm', form)
          document.body.append(form)
          window.makeFields('beforeStandalone', document.body)
          const blocked = document.createElement('div')
          blocked.className = 'rr-block'
          blocked.textContent = 'BlockedPrivateOak'
          window.secrets.push('BlockedPrivateOak')
          document.body.append(blocked)
          // Force fetch for inspectable request bodies, including unload flush.
          Object.defineProperty(navigator, 'sendBeacon', { value: undefined })
        }, types)
        await page.evaluate(mode => {
          window.__slim = { rate: mode === 'rate-zero' ? 0 : 100 }
          const script = document.createElement('script')
          script.src = '/p.js?c=fixture' + (mode === 'query-zero' ? '&rec=0' : '')
          if (mode === 'attribute-zero') script.dataset.rec = '0'
          document.body.append(script)
        }, mode)
        await page.waitForFunction(() => !!window.slimTDS)
        await page.waitForTimeout(250)
        await page.evaluate(() => {
          for (const el of document.querySelectorAll('input, textarea, select, [contenteditable]')) {
            const marker = el.getAttribute('data-private') + '_changed'
            window.secrets.push(marker)
            el.setAttribute('data-private', marker)
            if (el.matches('input:not([type=file])') && ['text', 'email', 'tel', 'url', 'search', 'password', 'hidden', 'checkbox', 'radio', 'button', 'submit', 'reset', 'image'].includes(el.type)) el.value = marker
            if (el.matches('textarea')) el.value = marker
            if (el.matches('select')) el.append(new Option(marker, marker, true, true))
            if (el.matches('[contenteditable]')) el.textContent = marker
            el.dispatchEvent(new Event('input', { bubbles: true }))
            el.dispatchEvent(new Event('change', { bubbles: true }))
          }
          document.querySelector('form').setAttribute('action', '/ChangedFormPrivate')
          window.secrets.push('ChangedFormPrivate')
          const form = document.createElement('form')
          form.setAttribute('data-private', 'DynamicFormPrivate')
          window.secrets.push('DynamicFormPrivate')
          window.makeFields('dynamicForm', form)
          document.body.append(form)
          window.makeFields('dynamicStandalone', document.body)
          document.querySelector('.rr-block').textContent = 'LaterBlockedPrivate'
          window.secrets.push('LaterBlockedPrivate')
          document.querySelector('#live').textContent = 'VisibleMutationBirch'
        })
        await page.locator('#card').click()
        await page.evaluate(() => window.scrollTo(0, 700))
        await page.waitForTimeout(2300)
        await page.evaluate(() => dispatchEvent(new Event('pagehide')))
        await page.waitForTimeout(150)
        const recordings = posts.filter(p => p.url === '/p/rec')
        const events = recordings.flatMap(p => p.payload.events)
        const json = JSON.stringify(events)
        const secrets = await page.evaluate(() => window.secrets)
        assert(secrets.length > 150)
        const leaks = secrets.filter(s => json.includes(s))
        const disabled = mode.endsWith('zero')
        assert(posts.some(p => p.url === '/p/event' && p.payload.event === 'pageview'))
        if (disabled) {
          assert.equal(recordings.length, 0)
          assert.equal(await page.evaluate(() => sessionStorage.getItem('slim_sid')), null)
        } else {
          assert(events.some(e => e.type === 2))
          assert(events.some(e => e.type === 4))
          assert(json.includes('VisibleCardCedar'))
          assert(json.includes('VisibleMutationBirch'))
          assert(events.some(e => e.type === 3 && e.data.source === 2))
          assert(events.some(e => e.type === 3 && e.data.source === 3))
          if (mode === 'positive-control') {
            for (const marker of ['BeforeFormPrivate', 'beforeForm_text_private', 'beforeStandalone_hidden_private', 'beforeStandalone_div_private', 'ChangedFormPrivate', 'dynamicForm_text_private', 'dynamicStandalone_div_private']) assert(leaks.includes(marker), marker)
          } else assert.deepEqual(leaks, [])
          assert(!json.includes('BlockedPrivateOak'))
          assert(!json.includes('LaterBlockedPrivate'))
        }
        assert.deepEqual(errors, [])
        assert.deepEqual(unexpected, [])
        results.push({ mode, chunks: recordings.length, events: events.length, sentinels: secrets.length, leaks, pageviews: posts.filter(p => p.url === '/p/event').length, errors, externalRequests: unexpected.length })
      } finally { await context.close() }
    }
    const proof = { bundleSha256: crypto.createHash('sha256').update(bundle).digest('hex'), results }
    if (process.env.PIXEL_PRIVACY_RESULT) fs.writeFileSync(process.env.PIXEL_PRIVACY_RESULT, JSON.stringify(proof, null, 2))
    console.log(JSON.stringify(proof, null, 2))
  } finally { await browser.close() }
})().catch(e => { console.error(e); process.exitCode = 1 })
