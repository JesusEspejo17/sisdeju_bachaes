$(function () {
  // Acordeón menú lateral
  var Accordion = function (el, multiple) {
    this.el = el || {};
    this.multiple = multiple || false;

    var dropdownlink = this.el.find('.dropdownlink');
    dropdownlink.on('click',
      { el: this.el, multiple: this.multiple },
      this.dropdown);
  };

  Accordion.prototype.dropdown = function (e) {
    var $el = e.data.el,
      $this = $(this),
      $next = $this.next();

    $next.slideToggle();
    $this.parent().toggleClass('open');

    if (!e.data.multiple) {
      $el.find('.submenuItems').not($next).slideUp().parent().removeClass('open');
    }
  };

  var accordion = new Accordion($('.accordion-menu'), false);

  // Menú desplegable del usuario al hacer clic
  const userInfo = document.querySelector(".user-info");
  const dropdown = document.querySelector(".user-dropdown");

  if (userInfo && dropdown) {
    userInfo.addEventListener("click", function (e) {
      e.stopPropagation(); // Evita que se cierre si haces clic dentro del user-info
      dropdown.classList.toggle("active");
    });

    document.addEventListener("click", function (e) {
      if (!userInfo.contains(e.target)) {
        dropdown.classList.remove("active");
      }
    });
  }
});
