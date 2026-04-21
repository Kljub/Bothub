document.addEventListener("DOMContentLoaded", function () {

    var switches = document.querySelectorAll(".js-command-switch");

    switches.forEach(function (button) {

        var inputId = button.getAttribute("data-input-id");
        if (!inputId) return;

        var input = document.getElementById(inputId);
        if (!input) return;

        function updateState() {
            if (input.value === "1") {
                button.classList.add("is-on");
                button.setAttribute("aria-checked", "true");
            } else {
                button.classList.remove("is-on");
                button.setAttribute("aria-checked", "false");
            }
        }

        button.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (input.value === "1") {
                input.value = "0";
            } else {
                input.value = "1";
            }

            updateState();
        });

        updateState();
    });

});