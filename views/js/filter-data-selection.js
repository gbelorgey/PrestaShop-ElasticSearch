(function ($) {
    'use strict';

    $(function () {
        var $modal  = $('#filter-values-modal');
        var $title  = $('.modal-title', $modal);
        var $select = $('select', $modal);
        var $option = $(document.createElement('option'));

        $('.js-filter-values-save')
            .on('click', function (event) {
                var data = $select.data();

                event.preventDefault();

                $modal.modal('hide');

                $.ajax({
                    url: data.url,
                    data: $.extend({}, data, {
                        values: $select.val(),
                    }),
                    type: 'post',
                    dataType: 'json',
                });
            })
        ;

        $modal
            .on('show.bs.modal', function (event) {
                var $link = $(event.relatedTarget);
                var data  = $.extend({}, $link.data(), {shops: [], categories: []});

                $('.js-filter-category:checked').each(function () {
                    data.categories.push($(this).attr('name').replace(/\D/g, ''));
                });

                $('[name^=checkBoxShopAsso_elasticsearch_menu_template]:checked').each(function () {
                    data.shops.push($(this).val());
                });

                $title.text(data.title);
                $select
                    .empty()
                    .data(data)
                ;

                $.ajax({
                    url: data.url,
                    data: {
                        filterShops: data.shops,
                        filterCategories: data.categories,
                        filterType: data.type,
                        filterTypeId: data.id,
                    },
                    type: 'get',
                    dataType: 'json',
                    success: function (json) {
                        json.forEach(function (elem) {
                            $option
                                .clone()
                                .val(elem.id)
                                .text(elem.name)
                                .prop('selected', elem.choosen)
                                .appendTo($select)
                            ;
                        });

                        $select.trigger('chosen:updated');
                    },
                });
            })
        ;
    });
}(window.jQuery));
