document.addEventListener('DOMContentLoaded', function () {
    const slides = document.querySelectorAll('.wc-banner-slide');
    let currentIndex = 0;

    setInterval(() => {
        slides.forEach((slide, index) => {
            slide.style.transform = `translateX(-${100 * currentIndex}%)`;
        });

        currentIndex = (currentIndex + 1) % slides.length;
    }, 3000);
});
