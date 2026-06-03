
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
	
			var custom_map = {
				'dimension10' : 'testproperty1'			};
	
			const trackingId = 'test-123456789-0';
			i = len = 0;

			for (i, len = window.dataLayer.length; i < len; i += 1) {
				if(window.dataLayer[i][0] == "config" && window.dataLayer[i][1] == trackingId ) {
					if(window.dataLayer[i].length > 2) {
						if( window.dataLayer[i][2]["custom_map"] !== undefined) {
							var datalayer_custom_map = window.dataLayer[i][2]["custom_map"];
							for([key, val] of Object.entries(custom_map)) {
								datalayer_custom_map[key] = val;
							}
						}
					} 
				}
			}

			window.addEventListener("load", function () {
				
				var update = function(data) {	
					gtag('event', 'fod', {
					'send_to': trackingId,
					'testproperty1' : data.device.testproperty1					});
				};

							fod.complete(update);
			
			});

		