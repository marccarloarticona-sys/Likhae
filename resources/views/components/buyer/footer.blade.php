@props(['guest' => false])

@if($guest)
<footer class="lk-ed-footer" role="contentinfo">
    <div class="lk-ed-footer-inner">
        <div class="lk-ed-footer-brand">
            <div class="lk-ed-footer-logo">LIKHAE</div>
            <p class="lk-ed-footer-tagline">"Crafted for Everyday Needs"</p>
        </div>

        <div class="lk-ed-footer-col">
            <h4>Quick Links</h4>
            <a href="{{ route('home') }}">Home</a>
            <a href="{{ route('products') }}">Categories</a>
            <a href="{{ route('products', ['sort' => 'best-selling']) }}">Deals</a>
            <a href="#about-likhae">About Us</a>
        </div>

        <div class="lk-ed-footer-col">
            <h4>Customer Service</h4>
            <a href="{{ route('login') }}">Help Center</a>
            <a href="{{ route('login') }}">Track Your Order</a>
            <a href="{{ route('login') }}">Returns &amp; Refunds</a>
            <a href="{{ route('login') }}">Contact Us</a>
        </div>

        <div class="lk-ed-footer-col">
            <h4>Follow Us</h4>
            <div class="lk-ed-footer-socials">
                <a href="{{ route('home') }}" aria-label="Facebook">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                </a>
                <a href="{{ route('home') }}" aria-label="Instagram">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>
                </a>
                <a href="{{ route('home') }}" aria-label="TikTok">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/></svg>
                </a>
                <a href="{{ route('home') }}" aria-label="YouTube">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46A2.78 2.78 0 0 0 1.46 6.42 29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.96-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" fill="currentColor"/></svg>
                </a>
            </div>
            <div class="lk-ed-footer-script">"More Good Days Together"</div>
        </div>
    </div>

    <div class="lk-ed-footer-bottom">
        <span>© {{ date('Y') }} LIKHAE. All rights reserved.</span>
        <span class="lk-ed-footer-closing">A more connected everyday. A brighter tomorrow.</span>
    </div>
</footer>
@else
<footer class="lk-footer">
    <span>© {{ date('Y') }} LIKHAE Marketplace. Buyer-first shopping experience.</span>
    <nav aria-label="Footer navigation">
        <a href="{{ route('buyer.orders') }}">My Purchases</a>
        <a href="{{ route('buyer.messages') }}">Support</a>
        <a href="{{ route('buyer.account') }}">Account</a>
    </nav>
</footer>
@endif
