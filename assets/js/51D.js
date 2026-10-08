
    jQuery(document).ready(function($) {

	    window.addEventListener( "load", function() {

		$("input[name='fiftyonedegrees_ga_update_cd_indices']").hide();
		var selected_values = getSelectedListValues();
		localStorage.removeItem('selectedValues');
		localStorage.setItem('selectedValues', selected_values);       
		});
	
		$('.51DPropertiesList select').change(function() {

			const selected_values_str = localStorage.getItem('selectedValues');
			var selected_values = selected_values_str.split(',');

			var curr_selected_values = getSelectedListValues();

			if(enabledButton === "enabled" && arrayMatch(curr_selected_values, selected_values) === false) {
				$("input[name='fiftyonedegrees_ga_update_cd_indices']").show();
			}else {
				$("input[name='fiftyonedegrees_ga_update_cd_indices']").hide();
			}

		}).trigger('change');

		// Master "Include" header toggle — flips every row checkbox.
		// WP_List_Table renders thead AND tfoot column headers, so the
		// master input appears twice in the DOM. Wire both copies via
		// class instead of id to avoid the duplicate-id pitfall.
		$('.51D-include-master').on('change', function() {
			var checked = $(this).is(':checked');
			$('.51D-include-cb').prop('checked', checked);
			// Drive master->row state without re-firing the row change
			// handler — that handler would reveal the Update button as
			// a side effect even when the propagation is just a sync.
			syncIncludeMaster(false);
			revealUpdateButtonIfChanged();
		});

		$('.51D-include-cb').on('change', function() {
			syncIncludeMaster(false);
			revealUpdateButtonIfChanged();
		});

		// Initial paint: align both master copies with the actual row
		// state. No reveal — page just loaded, admin hasn't acted.
		syncIncludeMaster(true);

		function syncIncludeMaster(initial) {
			var total = $('.51D-include-cb').length;
			var on    = $('.51D-include-cb:checked').length;
			var allOn = total > 0 && total === on;
			var mixed = on > 0 && on < total;
			$('.51D-include-master')
				.prop('checked', allOn)
				.prop('indeterminate', mixed);
		}

		function revealUpdateButtonIfChanged() {
			if (enabledButton === "enabled") {
				$("input[name='fiftyonedegrees_ga_update_cd_indices']").show();
			}
		}
    });

    var getSelectedListValues = function() {

    var selected_values = new Array();
    var selected_arr =  document.getElementsByTagName('select');
    for(k=0;k< selected_arr.length;k++)
    {
        sel = selected_arr[k];
        if(sel.name.indexOf('51D_') === 0){
            selected_values.push(sel.value);
        }
    }

    return selected_values;

    }

    var arrayMatch = function (arr1, arr2) {

    // Check if the arrays are the same length
    if (arr1.length !== arr2.length) return false;

    // Check if all items exist and are in the same order
    for (var i = 0; i < arr1.length; i++) {

        if (arr1[i] !== arr2[i]) return false;
    }

    return true;
    };