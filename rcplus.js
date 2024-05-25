/*
 * rcplus plugin
 * @author pulsejet
 */

var rcplus = {
    insertrow: function (evt) {
        // Check if we have the required data
        if (!rcmail.env.banner_avatar || !rcmail.env.banner_avatar[evt.uid])
            return;

        const { bold, text, color, image } = rcmail.env.banner_avatar[evt.uid];

        // Add column of avatar
        $('td.subject', evt.row.obj).before(
            $('<td />', { class: 'rc-avatar' }).append(`
                <div class="avatar ${bold ? 'bold-text' : ''}" style="color: ${color}">
                    <div>
                        <img src="${image}" alt="">
                        <span>${text}</span>
                    </div>
                    <span class="tick">&#10003;</span>
                </div>
            `).on('mousedown', function (event) {
                rcmail.message_list.select_row(evt.uid, CONTROL_KEY, true);
                event.stopPropagation();
            }).on('touchstart', function (event) {
                event.stopPropagation();
            })
        );

        // Add column of avatar if does not exit
        if ($('th.rc-avatar').length === 0 && $('th.subject').length > 0) {
            $('th.subject').before(
                $('<th/>', { class: 'rc-avatar' })
            );
        }
    }
};

window.rcmail && rcmail.addEventListener('init', function (evt) {
    if (rcmail.gui_objects.messagelist) {
        rcmail.addEventListener('insertrow', rcplus.insertrow);

        const _hrow = rcmail.message_list.highlight_row.bind(rcmail.message_list);
        rcmail.message_list.highlight_row = function (...args) {
            if (args[1]) {
                $(rcmail.message_list.tbody).addClass('multiselect');
            } else {
                $(rcmail.message_list.tbody).removeClass('multiselect');
            }
            _hrow(...args);
        }
    }
});
