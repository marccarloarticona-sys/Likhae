@php($mandatoryPolicies = request()->attributes->get('mandatory_policy_documents', collect()))
@if($mandatoryPolicies instanceof \Illuminate\Support\Collection && $mandatoryPolicies->isNotEmpty())
    <style>
        .lk-policy-modal-backdrop{position:fixed;inset:0;z-index:10000;display:grid;place-items:center;padding:18px;background:rgba(31,22,20,.72);backdrop-filter:blur(5px)}
        .lk-policy-modal{display:flex;flex-direction:column;width:min(100%,760px);max-height:min(90vh,920px);overflow:hidden;border:1px solid #e8d8cc;border-radius:22px;background:#fffdf9;color:#2b1a15;box-shadow:0 28px 90px rgba(0,0,0,.32)}
        .lk-policy-modal__header{padding:24px 26px 18px;border-bottom:1px solid #eee3db}.lk-policy-modal__eyebrow{color:#9d493b;font-size:10px;font-weight:900;letter-spacing:.13em;text-transform:uppercase}.lk-policy-modal__header h2{margin:7px 0 5px;font-size:22px;line-height:1.2}.lk-policy-modal__header p{margin:0;color:#715e55;font-size:13px;line-height:1.5}
        .lk-policy-modal__content{overflow:auto;padding:8px 26px 20px;overscroll-behavior:contain}.lk-policy-modal__document{padding:16px 0;border-bottom:1px solid #eee3db}.lk-policy-modal__document:last-child{border-bottom:0}.lk-policy-modal__document h3{margin:0;font-size:16px}.lk-policy-modal__meta{display:flex;flex-wrap:wrap;gap:8px 14px;margin:6px 0 13px;color:#806c62;font-size:11px;font-weight:700}.lk-policy-modal__body{white-space:pre-wrap;overflow-wrap:anywhere;color:#43322c;font-size:13px;line-height:1.68}
        .lk-policy-modal__footer{padding:17px 26px 20px;border-top:1px solid #eee3db;background:#fffaf5}.lk-policy-modal__ack{display:flex;gap:10px;align-items:flex-start;margin:0 0 10px;color:#42312b;font-size:12px;line-height:1.5}.lk-policy-modal__ack input{width:16px;height:16px;margin:1px 0 0;accent-color:#963d31;flex:0 0 auto}.lk-policy-modal__actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px}.lk-policy-modal__actions form{margin:0}.lk-policy-modal__agree,.lk-policy-modal__logout{border:0;border-radius:11px;padding:11px 16px;font:inherit;font-size:12px;font-weight:800;cursor:pointer}.lk-policy-modal__agree{background:#963d31;color:#fff}.lk-policy-modal__agree:disabled{background:#d9c6bc;color:#fff;cursor:not-allowed}.lk-policy-modal__logout{background:transparent;color:#795f54;text-decoration:underline;text-underline-offset:3px}
        .dark .lk-policy-modal{border-color:#4b3831;background:#211916;color:#f8eee9}.dark .lk-policy-modal__header,.dark .lk-policy-modal__footer,.dark .lk-policy-modal__document{border-color:#49352d}.dark .lk-policy-modal__header p,.dark .lk-policy-modal__meta{color:#c2aba0}.dark .lk-policy-modal__body,.dark .lk-policy-modal__ack{color:#eadbd3}.dark .lk-policy-modal__footer{background:#261c18}
        @media(max-width:600px){.lk-policy-modal-backdrop{padding:10px}.lk-policy-modal{max-height:94vh;border-radius:17px}.lk-policy-modal__header{padding:20px 18px 15px}.lk-policy-modal__content{padding:6px 18px 14px}.lk-policy-modal__footer{padding:14px 18px 17px}.lk-policy-modal__actions{align-items:stretch;flex-direction:column-reverse}.lk-policy-modal__agree{width:100%}.lk-policy-modal__logout{align-self:center}}
    </style>
    <div class="lk-policy-modal-backdrop" data-mandatory-policy-modal>
        <section class="lk-policy-modal" role="dialog" aria-modal="true" aria-labelledby="lk-policy-modal-title" aria-describedby="lk-policy-modal-description" tabindex="-1">
            <header class="lk-policy-modal__header">
                <span class="lk-policy-modal__eyebrow">Required policy update</span>
                <h2 id="lk-policy-modal-title">Review these policies to continue</h2>
                <p id="lk-policy-modal-description">Your account is protected while you review each current version. Dashboard features remain unavailable until you acknowledge them.</p>
            </header>
            <div class="lk-policy-modal__content" data-policy-content>
                @foreach($mandatoryPolicies as $policy)
                    <article class="lk-policy-modal__document" data-policy-document="{{ $policy->id }}">
                        <h3>{{ $policy->title }}</h3>
                        <div class="lk-policy-modal__meta"><span>Version {{ $policy->version }}</span><span>Effective {{ ($policy->effective_at ?? $policy->published_at)?->format('F j, Y') ?? 'Immediately' }}</span></div>
                        <div class="lk-policy-modal__body">{{ $policy->body }}</div>
                    </article>
                @endforeach
            </div>
            <footer class="lk-policy-modal__footer">
                @if($errors->has('policy_acceptances') || $errors->has('policy_acknowledgements'))
                    <p role="alert" style="margin:0 0 12px;color:#a52b22;font-size:12px;line-height:1.5">{{ $errors->first('policy_acceptances') ?: $errors->first('policy_acknowledgements') }}</p>
                @endif
                <form id="lk-policy-accept-form" method="POST" action="{{ route('policies.accept') }}" data-policy-accept-form>
                    @csrf
                    @foreach($mandatoryPolicies as $policy)
                        <input type="hidden" name="policy_acceptances[{{ $policy->id }}]" value="{{ $policy->version }}">
                        <label class="lk-policy-modal__ack"><input type="checkbox" name="policy_acknowledgements[{{ $policy->id }}]" value="1" data-policy-acknowledgement="{{ $policy->id }}" required disabled><span>I have read and acknowledge <strong>{{ $policy->title }}</strong>, version {{ $policy->version }}.</span></label>
                    @endforeach
                </form>
                <div class="lk-policy-modal__actions">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="lk-policy-modal__logout" type="submit">Log out / Decline</button>
                        </form>
                        <button class="lk-policy-modal__agree" type="submit" form="lk-policy-accept-form" data-policy-accept-submit disabled>I agree</button>
                </div>
            </footer>
        </section>
    </div>
    <script>
        (()=>{
            const backdrop=document.querySelector('[data-mandatory-policy-modal]');
            const dialog=backdrop?.querySelector('[role="dialog"]');
            const content=backdrop?.querySelector('[data-policy-content]');
            const form=backdrop?.querySelector('[data-policy-accept-form]');
            const submit=backdrop?.querySelector('[data-policy-accept-submit]');
            if(!backdrop||!dialog||!content||!form||!submit)return;
            document.querySelectorAll('body > *:not([data-mandatory-policy-modal])').forEach(node=>node.inert=true);
            const checks=[...form.querySelectorAll('input[type="checkbox"]')];
            const documents=new Map([...content.querySelectorAll('[data-policy-document]')].map(article=>[article.dataset.policyDocument,article]));
            const update=()=>{submit.disabled=!checks.length||!checks.every(input=>!input.disabled&&input.checked)};
            const unlockReachedPolicies=()=>{
                const contentBottom=content.getBoundingClientRect().bottom;
                checks.forEach(input=>{
                    const article=documents.get(input.dataset.policyAcknowledgement);
                    if(article&&article.getBoundingClientRect().bottom<=contentBottom+2)input.disabled=false;
                });
                update();
            };
            checks.forEach(input=>input.addEventListener('change',update));
            content.addEventListener('scroll',unlockReachedPolicies,{passive:true});
            window.addEventListener('resize',unlockReachedPolicies);
            document.addEventListener('keydown',event=>{
                if(event.key==='Escape'){event.preventDefault();event.stopPropagation();return}
                if(event.key!=='Tab')return;
                const focusable=[...dialog.querySelectorAll('button:not(:disabled),input:not(:disabled)')];
                if(!focusable.length)return;
                const first=focusable[0],last=focusable[focusable.length-1];
                if(event.shiftKey&&(document.activeElement===first||document.activeElement===dialog)){event.preventDefault();last.focus()}
                else if(!event.shiftKey&&(document.activeElement===last||document.activeElement===dialog)){event.preventDefault();first.focus()}
            },true);
            dialog.focus();
            unlockReachedPolicies();
        })();
    </script>
@endif
