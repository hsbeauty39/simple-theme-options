/**
 * Google Map field: Places search, map + marker, structured inputs, hidden JSON (sto_options).
 */
(function($) {
	'use strict';

	var mapsLoadPromise = null;

	function readPayloadFromDom($root) {
		var p = {
			formatted_address: '',
			address: '',
			street: '',
			city: '',
			state: '',
			zip: '',
			country: '',
			lat: '',
			lng: ''
		};
		$root.find('[data-sto-gmap-field]').each(function() {
			var k = $(this).attr('data-sto-gmap-field') || '';
			if (k && Object.prototype.hasOwnProperty.call(p, k)) {
				p[k] = String($(this).val() != null ? $(this).val() : '').trim();
			}
		});
		var $s = $root.find('[data-sto-gmap-search]').first();
		if ($s.length) {
			p.formatted_address = String($s.val() != null ? $s.val() : '').trim();
		}
		return p;
	}

	function writePayloadToDom($root, p) {
		var x;
		for (x in p) {
			if (!Object.prototype.hasOwnProperty.call(p, x)) {
				continue;
			}
			var $inp = $root.find('[data-sto-gmap-field="' + x + '"]');
			if ($inp.length) {
				$inp.val(p[x] == null ? '' : String(p[x]));
			}
		}
		var $s = $root.find('[data-sto-gmap-search]').first();
		if ($s.length) {
			$s.val(p.formatted_address == null ? '' : String(p.formatted_address));
		}
		$root.find('.sto-gmap-value').first().val(JSON.stringify(p)).trigger('change');
	}

	function componentsToPayload(place) {
		var p = {
			formatted_address: place.formatted_address || '',
			address: '',
			street: '',
			city: '',
			state: '',
			zip: '',
			country: '',
			lat: '',
			lng: ''
		};
		var comps = place.address_components || [];
		for (var i = 0; i < comps.length; i++) {
			var c = comps[i];
			var types = c.types || [];
			if (types.indexOf('street_number') !== -1) {
				p.address = c.long_name || '';
			} else if (types.indexOf('route') !== -1) {
				p.street = c.long_name || '';
			} else if (types.indexOf('locality') !== -1) {
				p.city = c.long_name || '';
			} else if (types.indexOf('administrative_area_level_1') !== -1) {
				p.state = c.short_name || c.long_name || '';
			} else if (types.indexOf('postal_code') !== -1) {
				p.zip = c.long_name || '';
			} else if (types.indexOf('country') !== -1) {
				p.country = c.long_name || '';
			}
		}
		if (place.geometry && place.geometry.location) {
			p.lat = String(place.geometry.location.lat());
			p.lng = String(place.geometry.location.lng());
		}
		return p;
	}

	function parseLatLng(p) {
		var la = parseFloat(p.lat);
		var lo = parseFloat(p.lng);
		if (!isFinite(la) || !isFinite(lo)) {
			return null;
		}
		if (la < -90 || la > 90 || lo < -180 || lo > 180) {
			return null;
		}
		return { lat: la, lng: lo };
	}

	function updateMarkerFromPayload($root, p) {
		var d = $root.data('stoGmapState');
		if (!d || !d.marker || !d.map || !window.google || !window.google.maps) {
			return;
		}
		var ll = parseLatLng(p);
		if (ll) {
			var latLng = new window.google.maps.LatLng(ll.lat, ll.lng);
			d.marker.setPosition(latLng);
			d.marker.setMap(d.map);
			d.map.setCenter(latLng);
		} else {
			d.marker.setMap(null);
		}
	}

	function ensureGoogleMaps(apiKey) {
		if (window.google && window.google.maps && window.google.maps.Map) {
			return $.Deferred().resolve().promise();
		}
		if (!apiKey) {
			return $.Deferred().reject(new Error('no_api_key')).promise();
		}
		if (mapsLoadPromise && mapsLoadPromise.state() === 'pending') {
			return mapsLoadPromise.promise();
		}
		mapsLoadPromise = $.Deferred();
		var cbName = 'stoGoogleMapsCb_' + String(Date.now());
		window[cbName] = function() {
			try {
				delete window[cbName];
			} catch (e1) {
				window[cbName] = undefined;
			}
			mapsLoadPromise.resolve();
		};
		var s = document.createElement('script');
		s.async = true;
		s.defer = true;
		s.src =
			'https://maps.googleapis.com/maps/api/js?key=' +
			encodeURIComponent(apiKey) +
			'&libraries=places&callback=' +
			encodeURIComponent(cbName);
		s.onerror = function() {
			mapsLoadPromise.reject(new Error('maps_script_failed'));
			mapsLoadPromise = null;
		};
		document.head.appendChild(s);
		return mapsLoadPromise.promise();
	}

	function resizeMap($root) {
		var d = $root.data('stoGmapState');
		if (!d || !d.map || !window.google || !window.google.maps) {
			return;
		}
		window.google.maps.event.trigger(d.map, 'resize');
		var ll = parseLatLng(readPayloadFromDom($root));
		if (ll) {
			d.map.setCenter(new window.google.maps.LatLng(ll.lat, ll.lng));
		}
	}

	function reverseGeocode($root, geocoder, latLng) {
		geocoder.geocode({ location: latLng }, function(results, status) {
			var p;
			if (status !== 'OK' || !results || !results[0]) {
				p = readPayloadFromDom($root);
				p.lat = String(latLng.lat());
				p.lng = String(latLng.lng());
			} else {
				p = componentsToPayload(results[0]);
			}
			writePayloadToDom($root, p);
			updateMarkerFromPayload($root, p);
			if (typeof window.stoApplyDependentFieldVisibility === 'function') {
				window.stoApplyDependentFieldVisibility();
			}
		});
	}

	function initMapUi($root) {
		var apiKey = String($root.attr('data-sto-gmap-api-key') || '').trim();
		var $notice = $root.find('[data-sto-gmap-no-key]').first();
		var $canvas = $root.find('[data-sto-gmap-canvas]').first();
		if (!apiKey) {
			$notice.removeAttr('hidden');
			$canvas.css('min-height', '120px');
			return;
		}
		$notice.attr('hidden', 'hidden');

		ensureGoogleMaps(apiKey)
			.done(function() {
				var payload = readPayloadFromDom($root);
				var ll = parseLatLng(payload);
				var center = ll || { lat: 38.8977, lng: -77.0365 };
				var map = new window.google.maps.Map($canvas[0], {
					center: center,
					zoom: ll ? 15 : 12,
					mapTypeControl: true,
					streetViewControl: true,
					fullscreenControl: true
				});
				var marker = new window.google.maps.Marker({
					position: ll ? new window.google.maps.LatLng(ll.lat, ll.lng) : null,
					map: ll ? map : null,
					draggable: true
				});
				var geocoder = new window.google.maps.Geocoder();

				$root.data('stoGmapState', { map: map, marker: marker, geocoder: geocoder });

				var searchEl = $root.find('[data-sto-gmap-search]').get(0);
				if (searchEl && window.google.maps.places) {
					var ac = new window.google.maps.places.Autocomplete(searchEl, {
						fields: ['address_components', 'formatted_address', 'geometry', 'name']
					});
					ac.bindTo('bounds', map);
					ac.addListener('place_changed', function() {
						var place = ac.getPlace();
						if (!place || !place.geometry || !place.geometry.location) {
							return;
						}
						var p3 = componentsToPayload(place);
						writePayloadToDom($root, p3);
						map.setCenter(place.geometry.location);
						map.setZoom(15);
						marker.setPosition(place.geometry.location);
						marker.setMap(map);
						if (typeof window.stoApplyDependentFieldVisibility === 'function') {
							window.stoApplyDependentFieldVisibility();
						}
					});
				}

				map.addListener('click', function(ev) {
					if (!ev.latLng) {
						return;
					}
					marker.setPosition(ev.latLng);
					marker.setMap(map);
					reverseGeocode($root, geocoder, ev.latLng);
				});

				marker.addListener('dragend', function() {
					var pos = marker.getPosition();
					if (!pos) {
						return;
					}
					reverseGeocode($root, geocoder, pos);
				});

				setTimeout(function() {
					resizeMap($root);
				}, 200);
			})
			.fail(function() {
				$notice.removeAttr('hidden');
			});
	}

	function bindOne($root) {
		if ($root.data('stoGmapBound')) {
			setTimeout(function() {
				resizeMap($root);
			}, 80);
			return;
		}
		$root.data('stoGmapBound', 1);

		$root.on('input change', '[data-sto-gmap-field], [data-sto-gmap-search]', function() {
			var p = readPayloadFromDom($root);
			$root.find('.sto-gmap-value').first().val(JSON.stringify(p)).trigger('change');
			updateMarkerFromPayload($root, p);
			if (typeof window.stoApplyDependentFieldVisibility === 'function') {
				window.stoApplyDependentFieldVisibility();
			}
		});

		initMapUi($root);
	}

	window.stoInitGoogleMapFields = function($scope) {
		var $ctx = $scope && $scope.length ? $scope : $(document);
		$ctx.find('.sto-gmap[data-sto-gmap="1"]').each(function() {
			bindOne($(this));
		});
	};
})(jQuery);
