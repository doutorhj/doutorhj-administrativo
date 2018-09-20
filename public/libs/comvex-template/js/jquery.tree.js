/**
 * Theme: Minton Admin Template
 * Author: Coderthemes
 * Tree view
 */

$( document ).ready(function() {
    // Basic
    $('.cvx-basic-tree').jstree({
        'core' : {
            'themes' : {
                'responsive': false
            }
        },
        'types' : {
            'default' : {
                'icon' : 'fa fa-folder'
            },
            'file' : {
                'icon' : 'fa fa-file'
            },
            'has_permission' : {
                'icon' : 'ti-check-box'
            },
            'not_allowed' : {
                'icon' : 'ti-na'
            }
        },
        'plugins' : ['types']
    });

    // Checkbox
    $('#checkTree').jstree({
        'core' : {
            'themes' : {
                'responsive': false
            }
        },
        'types' : {
            'default' : {
                'icon' : 'fa fa-folder'
            },
            'file' : {
                'icon' : 'fa fa-file'
            }
        },
        'plugins' : ['types', 'checkbox']
    });

    // Drag & Drop
    $('#dragTree').jstree({
        'core' : {
            'check_callback' : true,
            'themes' : {
                'responsive': false
            }
        },
        'types' : {
            'default' : {
                'icon' : 'fa fa-folder'
            },
            'file' : {
                'icon' : 'fa fa-file'
            }
        },
        'plugins' : ['types', 'dnd']
    });

    // Ajax
    $('#ajaxTree').jstree({
        'core' : {
            'check_callback' : true,
            'themes' : {
                'responsive': false
            },
            'data' : {
                'url' : function (node) {
                    return node.id === '#' ? '../plugins/jstree/ajax_roots.json' : '../plugins/jstree/ajax_children.json';
                },
                'data' : function (node) {
                    return { 'id' : node.id };
                }
            }
        },
        "types" : {
            'default' : {
                'icon' : 'fa fa-folder'
            },
            'file' : {
                'icon' : 'fa fa-file'
            }
        },
        "plugins" : [ "contextmenu", "dnd", "search", "state", "types", "wholerow" ]
    });
});