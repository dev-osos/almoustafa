/**
 * Lazy Loading Manager
 * نظام التحميل الكسول للصور والموارد
 */

(function() {
    'use strict';

    // دعم Intersection Observer
    const supportsIntersectionObserver = 'IntersectionObserver' in window;
    
    // إعدادات افتراضية
    const DEFAULT_OPTIONS = {
        root: null,
        rootMargin: '50px',
        threshold: 0.01
    };

    /**
     * تهيئة lazy loading للصور
     */
    function initLazyImages() {
        const images = document.querySelectorAll('img[data-src], img[loading="lazy"]');
        
        if (!supportsIntersectionObserver) {
            // Fallback للمتصفحات القديمة
            images.forEach(img => {
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                }
            });
            return;
        }

        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    
                    // إضافة placeholder أثناء التحميل
                    if (!img.classList.contains('lazy-loading')) {
                        img.classList.add('lazy-loading');
                        
                        // إضافة spinner أو placeholder
                        const placeholder = document.createElement('div');
                        placeholder.className = 'lazy-placeholder';
                        placeholder.style.cssText = `
                            position: absolute;
                            top: 0;
                            left: 0;
                            width: 100%;
                            height: 100%;
                            background: #f0f0f0;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            z-index: 1;
                        `;
                        placeholder.innerHTML = '<div class="spinner-border spinner-border-sm text-primary"></div>';
                        
                        const parent = img.parentElement;
                        if (parent && getComputedStyle(parent).position === 'static') {
                            parent.style.position = 'relative';
                        }
                        if (parent) {
                            parent.insertBefore(placeholder, img);
                        }
                    }
                    
                    // تحميل الصورة
                    if (img.dataset.src) {
                        const tempImg = new Image();
                        
                        tempImg.onload = function() {
                            img.src = this.src;
                            img.classList.remove('lazy-loading');
                            img.classList.add('lazy-loaded');
                            
                            // إزالة placeholder
                            const placeholder = img.parentElement.querySelector('.lazy-placeholder');
                            if (placeholder) {
                                placeholder.style.transition = 'opacity 0.3s';
                                placeholder.style.opacity = '0';
                                setTimeout(() => placeholder.remove(), 300);
                            }
                            
                            // إزالة data-src
                            img.removeAttribute('data-src');
                            
                            // إرسال event
                            img.dispatchEvent(new CustomEvent('lazyloaded'));
                        };
                        
                        tempImg.onerror = function() {
                            img.classList.remove('lazy-loading');
                            img.classList.add('lazy-error');
                            
                            // إزالة placeholder
                            const placeholder = img.parentElement.querySelector('.lazy-placeholder');
                            if (placeholder) {
                                placeholder.remove();
                            }
                            
                            // إظهار رسالة خطأ
                            img.alt = img.alt || 'فشل تحميل الصورة';
                            img.style.backgroundColor = '#f0f0f0';
                            
                            img.dispatchEvent(new CustomEvent('lazyerror'));
                        };
                        
                        tempImg.src = img.dataset.src;
                    } else if (img.loading === 'lazy') {
                        // الصورة تستخدم loading="lazy" natively
                        img.classList.add('lazy-loaded');
                    }
                    
                    observer.unobserve(img);
                }
            });
        }, DEFAULT_OPTIONS);

        images.forEach(img => {
            imageObserver.observe(img);
        });
    }

    /**
     * تهيئة lazy loading للـ iframes
     */
    function initLazyIframes() {
        const iframes = document.querySelectorAll('iframe[data-src]');
        
        if (!supportsIntersectionObserver) {
            iframes.forEach(iframe => {
                if (iframe.dataset.src) {
                    iframe.src = iframe.dataset.src;
                    iframe.removeAttribute('data-src');
                }
            });
            return;
        }

        const iframeObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const iframe = entry.target;
                    
                    if (iframe.dataset.src) {
                        iframe.src = iframe.dataset.src;
                        iframe.removeAttribute('data-src');
                        iframe.classList.add('lazy-loaded');
                        
                        iframe.dispatchEvent(new CustomEvent('lazyloaded'));
                    }
                    
                    observer.unobserve(iframe);
                }
            });
        }, DEFAULT_OPTIONS);

        iframes.forEach(iframe => {
            iframeObserver.observe(iframe);
        });
    }

    /**
     * تهيئة lazy loading للـ scripts
     */
    function initLazyScripts() {
        const scripts = document.querySelectorAll('script[data-src]');
        
        if (!supportsIntersectionObserver) {
            scripts.forEach(script => {
                if (script.dataset.src) {
                    const newScript = document.createElement('script');
                    newScript.src = script.dataset.src;
                    if (script.async) newScript.async = true;
                    if (script.defer) newScript.defer = true;
                    script.parentNode.replaceChild(newScript, script);
                }
            });
            return;
        }

        const scriptObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const script = entry.target;
                    
                    if (script.dataset.src) {
                        const newScript = document.createElement('script');
                        newScript.src = script.dataset.src;
                        if (script.async) newScript.async = true;
                        if (script.defer) newScript.defer = true;
                        script.parentNode.replaceChild(newScript, script);
                        
                        script.dispatchEvent(new CustomEvent('lazyloaded'));
                    }
                    
                    observer.unobserve(script);
                }
            });
        }, DEFAULT_OPTIONS);

        scripts.forEach(script => {
            scriptObserver.observe(script);
        });
    }

    /**
     * تهيئة lazy loading للـ CSS
     */
    function initLazyStylesheets() {
        const stylesheets = document.querySelectorAll('link[rel="stylesheet"][data-href]');
        
        if (!supportsIntersectionObserver) {
            stylesheets.forEach(link => {
                if (link.dataset.href) {
                    link.href = link.dataset.href;
                    link.removeAttribute('data-href');
                }
            });
            return;
        }

        const stylesheetObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const link = entry.target;
                    
                    if (link.dataset.href) {
                        link.href = link.dataset.href;
                        link.removeAttribute('data-href');
                        link.classList.add('lazy-loaded');
                        
                        link.dispatchEvent(new CustomEvent('lazyloaded'));
                    }
                    
                    observer.unobserve(link);
                }
            });
        }, DEFAULT_OPTIONS);

        stylesheets.forEach(link => {
            stylesheetObserver.observe(link);
        });
    }

    /**
     * تهيئة جميع أنواع lazy loading
     */
    function init() {
        // تهيئة lazy loading للصور
        initLazyImages();
        
        // تهيئة lazy loading للـ iframes
        initLazyIframes();
        
        // تهيئة lazy loading للـ scripts
        initLazyScripts();
        
        // تهيئة lazy loading للـ CSS
        initLazyStylesheets();
        
        // مراقبة العناصر الجديدة المضافة ديناميكياً
        if (supportsIntersectionObserver && 'MutationObserver' in window) {
            const mutationObserver = new MutationObserver(mutations => {
                mutations.forEach(mutation => {
                    mutation.addedNodes.forEach(node => {
                        if (node.nodeType === 1) { // Element node
                            // فحص الصور الجديدة
                            const newImages = node.querySelectorAll ? node.querySelectorAll('img[data-src], img[loading="lazy"]') : [];
                            newImages.forEach(img => {
                                const imageObserver = new IntersectionObserver((entries, observer) => {
                                    entries.forEach(entry => {
                                        if (entry.isIntersecting) {
                                            const img = entry.target;
                                            if (img.dataset.src) {
                                                img.src = img.dataset.src;
                                                img.removeAttribute('data-src');
                                                img.classList.add('lazy-loaded');
                                            }
                                            observer.unobserve(img);
                                        }
                                    });
                                }, DEFAULT_OPTIONS);
                                imageObserver.observe(img);
                            });
                        }
                    });
                });
            });
            
            mutationObserver.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }

    // تهيئة عند تحميل DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // تصدير الوظائف للاستخدام العام
    window.LazyLoading = {
        init: init,
        initImages: initLazyImages,
        initIframes: initLazyIframes,
        initScripts: initLazyScripts,
        initStylesheets: initLazyStylesheets
    };

    console.log('[LazyLoading] Initialized');
})();

