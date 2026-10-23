'use strict';
(() => {
  let toastTimer;
  const toast = (message) => {
    const node = document.getElementById('toast');
    if (!node) return;
    node.textContent = message; node.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { node.hidden = true; }, 2600);
  };
  const copy = async (value) => {
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(value);
      else {
        const input = document.createElement('textarea'); input.value = value;
        input.setAttribute('readonly', ''); input.className = 'form-control';
        document.body.append(input); input.select();
        const ok = document.execCommand('copy'); input.remove();
        if (!ok) throw new Error('clipboard');
      }
      toast('已复制到剪贴板');
    } catch (_) { toast('复制失败，请选中文本手动复制'); }
  };
  document.querySelectorAll('[data-copy-target]').forEach(button => {
    button.addEventListener('click', () => copy(document.getElementById(button.dataset.copyTarget).textContent));
  });
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => { if (!window.confirm(form.dataset.confirm)) event.preventDefault(); });
  });
  const form = document.getElementById('lookup-form');
  if (!form) return;
  const input = document.getElementById('ip-input');
  const queryButton = document.getElementById('lookup-button');
  const refreshButton = document.getElementById('refresh-button');
  const error = document.getElementById('lookup-error');
  const status = document.getElementById('lookup-status');
  let current = null, currentAddress = '', busy = false;
  const labels = {hit:'缓存命中',miss:'首次查询',fresh:'已刷新',stale:'缓存兜底'};
  const details = (id, pairs) => {
    const list = document.createElement('dl'); list.className = 'detail-list';
    pairs.forEach(([label, value]) => {
      const row = document.createElement('div'); row.className = 'detail-row';
      const key = document.createElement('dt'); key.textContent = label;
      const content = document.createElement('dd');
      if (value === null || value === undefined) { content.textContent = '未提供'; content.className = 'unknown-value'; }
      else if (value === '') { content.textContent = '空值（上游已提供）'; content.className = 'unknown-value'; }
      else content.textContent = String(value);
      row.append(key, content); list.append(row);
    });
    document.getElementById(id).replaceChildren(list);
  };
  // 对已验证的 JSON 文本按词元排版，不把数值转为 JavaScript Number。
  const prettyJson = (text) => {
    const tokens = text.match(/"(?:\\.|[^"\\])*"|[{}\[\],:]|[^\s{}\[\],:]+/g) || [];
    let output = '', depth = 0;
    const newline = () => '\n' + '  '.repeat(depth);
    tokens.forEach((token, index) => {
      if (token === '{' || token === '[') {
        output += token;
        if (tokens[index + 1] !== '}' && tokens[index + 1] !== ']') { depth++; output += newline(); }
      } else if (token === '}' || token === ']') {
        if (tokens[index - 1] !== '{' && tokens[index - 1] !== '[') { depth--; output += newline(); }
        output += token;
      } else if (token === ',') output += ',' + newline();
      else if (token === ':') output += ': ';
      else output += token;
    });
    return output;
  };
  const render = (response) => {
    const data = response.data, meta = response.meta;
    document.getElementById('result-ip').textContent = data.ip;
    document.getElementById('version-badge').textContent = `IPv${meta.ip_version}`;
    const cacheBadge = document.getElementById('cache-badge');
    cacheBadge.textContent = labels[meta.cache_status] || meta.cache_status;
    cacheBadge.className = `badge ${meta.stale ? 'bg-yellow-lt' : 'bg-green-lt'}`;
    document.getElementById('stale-warning').hidden = !meta.stale;
    details('location-details', [['国家 / 地区',data.country],['国家代码',data.country_code],['省 / 地区',data.region],['地区代码',data.region_code],['城市',data.city],['邮政编码',data.postal_code],['洲代码',data.continent_code],['时区',data.timezone],['UTC 偏移（秒）',data.offset]]);
    details('network-details', [['运营商 ISP',data.isp],['组织归属',data.organization],['ASN',data.asn],['ASN 组织',data.asn_organization]]);
    details('coordinate-details', [['纬度 Latitude',data.latitude],['经度 Longitude',data.longitude]]);
    details('cache-details', [['地址类型',`IPv${meta.ip_version}`],['数据来源',meta.source],['缓存状态',labels[meta.cache_status]],['抓取时间',meta.fetched_at],['过期时间',meta.expires_at],['旧缓存',meta.stale ? '是' : '否']]);
    document.getElementById('raw-json').textContent = prettyJson(response.raw_json);
    document.getElementById('empty-state').hidden = true;
    document.getElementById('results').hidden = false;
  };
  const lookup = async (refresh = false) => {
    if (busy || !form.reportValidity()) return;
    busy = true; queryButton.disabled = true; refreshButton.disabled = true;
    error.hidden = true; status.textContent = refresh ? '正在刷新上游数据…' : '正在查询，请稍候…';
    // 隐藏先前结果，避免失败的新查询被误认成旧结果。
    document.getElementById('results').hidden = true;
    const requested = refresh ? currentAddress : input.value.trim();
    const body = new URLSearchParams({ip:requested,csrf:form.elements.csrf.value,refresh:refresh ? '1' : '0'});
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 25000);
      let response;
      try { response = await fetch('/query.php', {method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal}); }
      finally { clearTimeout(timeout); }
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error?.message || '查询失败，请稍后重试。');
      current = result; currentAddress = requested; render(result); status.textContent = '';
      if (refresh) toast(result.meta.stale ? '刷新失败，已返回缓存' : '数据已刷新');
    } catch (failure) {
      current = null;
      error.textContent = failure.name === 'AbortError' ? '请求超时，请稍后重试。' : (failure.message || '网络请求失败。');
      error.hidden = false; status.textContent = '';
    } finally { busy = false; queryButton.disabled = false; refreshButton.disabled = false; }
  };
  form.addEventListener('submit', event => { event.preventDefault(); lookup(); });
  document.querySelectorAll('[data-example]').forEach(button => button.addEventListener('click', () => {
    if (busy) return;
    input.value = button.dataset.example; lookup();
  }));
  refreshButton.addEventListener('click', () => { if (current) lookup(true); });
  document.getElementById('copy-ip').addEventListener('click', () => { if (current) copy(current.data.ip); });
  document.getElementById('copy-json').addEventListener('click', () => { if (current) copy(prettyJson(current.raw_json)); });
})();
