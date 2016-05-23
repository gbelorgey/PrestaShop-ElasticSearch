(function ($) {
    'use strict';

    $(function () {
        var $modal  = $('#filter-values-modal');
        var $title  = $('.modal-title', $modal);
        var $select = $('select', $modal);
        var $option = $(document.createElement('option'));

        $modal
            .on('show.bs.modal', function (event) {
                var $link = $(event.relatedTarget);
                var data  = $link.data();

                $title.text(data.title);
                $select
                    .empty()
                ;

                $.ajax({
                    url: data.url,
                    data: {
                        submitElasticSearchFilterChooseValues: true,
                        filterType: data.type,
                        filterValue: data.id,
                        id_shop: data.shop,
                    },
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
