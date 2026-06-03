
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('js', new Date());
	
			const configuration = {
				<!-- 'cookieDomain': 'none', -->
				'send_page_view': 'true',
				'custom_map' : {
					'dimension1' : 'testproperty1',
                    'dimension5' : 'testproperty2'				}				
			};
	
			const trackingId = 'test-123456789-0';
			gtag('config', trackingId, configuration);
	
			window.addEventListener("load", function () {
				
				var update = function(data){		
					gtag('event', 'fod', {
					'send_to': trackingId,
					'testproperty1' : data.device.testproperty1,
                        'testproperty2' : data.location.testproperty2					});
				};

							fod.complete(update, "location");
			
			});

		