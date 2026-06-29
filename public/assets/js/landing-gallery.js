/**
 * Landing Page Gallery Navigation
 * Handles slide transitions and indicator states
 */

(function() {
    'use strict';

    let currentSlide = 0;
    const slides = document.querySelectorAll('.gallery-slide');
    const indicators = document.querySelectorAll('.gallery-indicator');
    const prevBtn = document.querySelector('.gallery-btn-prev');
    const nextBtn = document.querySelector('.gallery-btn-next');

    if (slides.length === 0) return;

    function showSlide(index) {
        // Wrap around
        if (index < 0) {
            index = slides.length - 1;
        } else if (index >= slides.length) {
            index = 0;
        }

        // Remove active class from all slides and indicators
        slides.forEach(slide => slide.classList.remove('active'));
        indicators.forEach(indicator => indicator.classList.remove('active'));

        // Add active class to current slide and indicator
        slides[index].classList.add('active');
        indicators[index].classList.add('active');

        currentSlide = index;
    }

    // Previous button
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            showSlide(currentSlide - 1);
        });
    }

    // Next button
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            showSlide(currentSlide + 1);
        });
    }

    // Indicator clicks
    indicators.forEach((indicator, index) => {
        indicator.addEventListener('click', () => {
            showSlide(index);
        });
    });

    // Optional: Auto-advance every 8 seconds
    // Uncomment if desired
    // setInterval(() => {
    //     showSlide(currentSlide + 1);
    // }, 8000);

})();
