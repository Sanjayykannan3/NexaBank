const menuBtn = document.getElementById("menuBtn");

menuBtn.addEventListener("click", function () {
    alert("Mobile navigation coming soon.");
});

document.querySelectorAll('a[href^="#"]').forEach(link => {

    link.addEventListener("click", function (event) {

        const target = document.querySelector(this.getAttribute("href"));

        if (target) {
            event.preventDefault();

            target.scrollIntoView({
                behavior: "smooth"
            });
        }

    });

});
