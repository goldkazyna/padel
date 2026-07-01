<style>
:root{
  --ov-bg:#0a0a0b; --ov-card:#16161a; --ov-card2:#1e1e24; --ov-border:#27272a;
  --ov-accent:#22c55e; --ov-text:#f4f4f5; --ov-text2:#a1a1aa; --ov-text3:#71717a;
  --ov-amber:#eab34e; --ov-red:#f0554d;
}
.ov-wrap{max-width:820px;margin:0 auto;padding:20px 16px 40px;color:var(--ov-text);}
.ov-head{display:flex;align-items:center;gap:12px;margin-bottom:18px;}
.ov-head h1{font-size:24px;font-weight:800;margin:0;}
.ov-back{width:40px;height:40px;border-radius:10px;background:var(--ov-card);border:1px solid var(--ov-border);
  display:flex;align-items:center;justify-content:center;color:var(--ov-text);text-decoration:none;font-size:18px;}
.ov-back:hover{background:var(--ov-card2);}
.ov-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.ov-tab{padding:9px 15px;border-radius:10px;background:var(--ov-card);border:1px solid var(--ov-border);
  color:var(--ov-text2);text-decoration:none;font-weight:600;font-size:14px;}
.ov-tab:hover{background:var(--ov-card2);}
.ov-tab.active{background:var(--ov-card2);color:#fff;border-color:#3f3f46;}
.ov-tab.active.ending{border-color:var(--ov-amber);color:var(--ov-amber);}
.ov-tab.active.ended{border-color:var(--ov-red);color:var(--ov-red);}
.ov-n{font-weight:800;margin-left:4px;opacity:.85;}
.ov-list{display:flex;flex-direction:column;gap:8px;}
.ov-item{display:flex;align-items:center;gap:12px;background:var(--ov-card);border:1px solid var(--ov-border);
  border-radius:12px;padding:13px 15px;}
.ov-main{flex:1;min-width:0;}
.ov-name{font-weight:700;font-size:15px;color:#fff;}
.ov-sub{color:var(--ov-text2);font-size:13px;margin-top:2px;}
.ov-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
.ov-bal{font-weight:800;color:var(--ov-text);font-size:14px;}
.ov-date{color:var(--ov-text3);font-size:13px;}
.ov-badge,.ov-rem{font-size:12px;font-weight:800;padding:4px 10px;border-radius:8px;}
.green{color:var(--ov-accent);background:rgba(34,197,94,.14);}
.amber{color:var(--ov-amber);background:rgba(234,179,78,.16);}
.red{color:var(--ov-red);background:rgba(240,85,77,.14);}
.ov-open{width:38px;height:38px;flex:none;border-radius:10px;background:var(--ov-card2);
  border:1px solid var(--ov-border);display:flex;align-items:center;justify-content:center;
  color:var(--ov-accent);text-decoration:none;font-size:17px;}
.ov-open:hover{background:#26262c;border-color:#3f3f46;}
.ov-arch{width:38px;height:38px;flex:none;border-radius:10px;background:var(--ov-card2);
  border:1px solid var(--ov-border);display:flex;align-items:center;justify-content:center;
  color:var(--ov-text2);cursor:pointer;font-size:16px;}
.ov-arch:hover{background:#26262c;color:#fff;}
.ov-arch.restore{color:var(--ov-accent);}
.ov-flash{background:rgba(34,197,94,.14);color:var(--ov-accent);border-radius:10px;
  padding:10px 14px;margin-bottom:12px;font-weight:600;font-size:14px;}
.ov-empty{text-align:center;color:var(--ov-text3);padding:48px 0;}
/* Кнопки-входы на странице клиентов */
.clients-overview-btns{display:flex;gap:10px;margin:0 0 4px;flex-wrap:wrap;}
.clients-overview-btns a{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border-radius:10px;
  background:var(--ov-card,#16161a);border:1px solid var(--ov-border,#27272a);color:#f4f4f5;text-decoration:none;
  font-weight:700;font-size:14px;}
.clients-overview-btns a:hover{background:#1e1e24;border-color:#3f3f46;}
.clients-overview-btns i{color:#22c55e;font-size:17px;}
</style>
