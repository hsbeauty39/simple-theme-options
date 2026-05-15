/**
 * Location map field (Leaflet + OSM + Nominatim): Enter search fills fields; field edits sync the search line; lat/lng debounce to reverse geocode.
 */
(function($) {
	'use strict';

	var NOM_GAP_MS = 1100;
	var nomQueue = [];
	var nomBusy = false;

	function enqueueNomRequest(run) {
		return new Promise(function(resolve, reject) {
			nomQueue.push({ run: run, resolve: resolve, reject: reject });
			processNomQueue();
		});
	}

	function processNomQueue() {
		if (nomBusy || nomQueue.length === 0) {
			return;
		}
		nomBusy = true;
		var item = nomQueue.shift();
		Promise.resolve(item.run())
			.then(item.resolve, item.reject)
			.finally(function() {
				setTimeout(function() {
					nomBusy = false;
					processNomQueue();
				}, NOM_GAP_MS);
			});
	}

	function getNomEmail() {
		var cfg = window.stoGoogleMapField && window.stoGoogleMapField.nominatim;
		if (cfg && cfg.email) {
			return String(cfg.email).trim();
		}
		return '';
	}

	function nominatimExtraQuery() {
		var em = getNomEmail();
		return em ? '&email=' + encodeURIComponent(em) : '';
	}

	function acceptLanguageHeader() {
		var l = document.documentElement && document.documentElement.getAttribute('lang');
		return l && String(l).trim() ? String(l).trim() : 'en';
	}

	function fetchNom(url) {
		return fetch(url + nominatimExtraQuery(), {
			credentials: 'omit',
			headers: {
				Accept: 'application/json',
				'Accept-Language': acceptLanguageHeader()
			}
		}).then(function(r) {
			if (!r.ok) {
				throw new Error('nom_http_' + r.status);
			}
			return r.json();
		});
	}

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

	function firstNonEmpty() {
		for (var i = 0; i < arguments.length; i++) {
			var v = arguments[i];
			if (v != null && String(v).trim() !== '') {
				return String(v).trim();
			}
		}
		return '';
	}

	function buildLineFromFields(p) {
		var line1 = [p.address, p.street]
			.map(function(x) {
				return x != null ? String(x).trim() : '';
			})
			.filter(Boolean)
			.join(' ')
			.trim();
		var rest = [p.city, p.state, p.zip, p.country]
			.map(function(x) {
				return x != null ? String(x).trim() : '';
			})
			.filter(Boolean);
		var bits = [];
		if (line1) {
			bits.push(line1);
		}
		return bits.concat(rest).join(', ');
	}

	function applyDisplayNameFallbacks(p) {
		var raw = (p.formatted_address || '').trim();
		if (!raw) {
			return;
		}
		var parts = raw.split(',').map(function(s) {
			return s.trim();
		}).filter(Boolean);
		if (!parts.length) {
			return;
		}
		if (!p.country) {
			p.country = parts[parts.length - 1];
		}
		if (!p.state && parts.length >= 2) {
			p.state = parts[parts.length - 2];
		}
		if (!p.city && parts.length >= 3) {
			p.city = parts[parts.length - 3];
		}
		if (!p.street && parts.length >= 4) {
			var head = parts[0];
			var m = /^(\d+[a-zA-Z0-9./-]*)\s+(.+)$/.exec(head);
			if (m) {
				if (!p.address) {
					p.address = m[1];
				}
				if (!p.street) {
					p.street = m[2];
				}
			} else if (!p.street) {
				p.street = head;
			}
		}
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

	function nominatimToPayload(data) {
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
		if (!data || typeof data !== 'object') {
			return p;
		}
		p.formatted_address = data.display_name ? String(data.display_name) : '';
		if (data.lat != null && data.lon != null) {
			p.lat = String(data.lat);
			p.lng = String(data.lon);
		}
		var a = data.address && typeof data.address === 'object' ? data.address : {};
		p.address = firstNonEmpty(a.house_number);
		p.street = firstNonEmpty(
			a.road,
			a.pedestrian,
			a.path,
			a.footway,
			a.residential,
			a.neighbourhood,
			a.quarter
		);
		p.city = firstNonEmpty(
			a.city,
			a.town,
			a.village,
			a.municipality,
			a.city_district,
			a.suburb,
			a.district,
			a.allotments,
			a.neighbourhood,
			a.quarter,
			a.isolated_dwelling
		);
		if (!p.city) {
			p.city = firstNonEmpty(a.county, a.state_district);
		}
		p.state = firstNonEmpty(a.state, a.region, a.state_district, a.county, a['ISO3166-2-lvl4']);
		p.zip = firstNonEmpty(a.postcode);
		p.country = firstNonEmpty(a.country);
		if (!p.country) {
			p.country = firstNonEmpty(a.continent);
		}
		if (!p.state && a.continent && p.country && p.country !== String(a.continent)) {
			p.state = firstNonEmpty(a.region, a.county);
		}
		if (!p.city && data.name && String(data.name).trim() !== '') {
			var nm = String(data.name).trim();
			var t = data.addresstype || data.type || '';
			if (t === 'city' || t === 'town' || t === 'village' || t === 'hamlet' || t === 'suburb' || t === 'neighbourhood') {
				p.city = nm;
			} else if (t === 'state' || t === 'region' || t === 'county') {
				if (!p.state) {
					p.state = nm;
				}
			} else if (t === 'country' && !p.country) {
				p.country = nm;
			} else if ((t === 'continent' || t === 'sea') && !p.country) {
				p.country = nm;
			} else if (!p.city && !p.state && !p.street) {
				p.city = nm;
			}
		}
		applyDisplayNameFallbacks(p);
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
		if (!d || !d.map || !d.marker || !window.L) {
			return;
		}
		var ll = parseLatLng(p);
		if (ll) {
			var latlng = L.latLng(ll.lat, ll.lng);
			d.marker.setLatLng(latlng);
			d.marker.addTo(d.map);
			d.map.setView(latlng, Math.max(d.map.getZoom() || 0, 14), { animate: false });
		} else if (d.map.hasLayer(d.marker)) {
			d.map.removeLayer(d.marker);
		}
	}

	function resizeMap($root) {
		var d = $root.data('stoGmapState');
		if (!d || !d.map || !window.L) {
			return;
		}
		d.map.invalidateSize(true);
		var ll = parseLatLng(readPayloadFromDom($root));
		if (ll) {
			d.map.setView(L.latLng(ll.lat, ll.lng), d.map.getZoom(), { animate: false });
		}
	}

	function showGmapMsg($root, text) {
		var $m = $root.find('[data-sto-gmap-msg]').first();
		if (!$m.length) {
			return;
		}
		clearTimeout($m.data('stoGmsgT'));
		if (text) {
			$m.text(text).removeAttr('hidden');
			$m.data(
				'stoGmsgT',
				setTimeout(function() {
					$m.text('').attr('hidden', 'hidden');
				}, 4500)
			);
		} else {
			$m.text('').attr('hidden', 'hidden');
		}
	}

	function reverseGeocode($root, lat, lng) {
		var i18n = $root.data('stoGmapI18n') || {};
		var url =
			'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' +
			encodeURIComponent(String(lat)) +
			'&lon=' +
			encodeURIComponent(String(lng));
		enqueueNomRequest(function() {
			return fetchNom(url)
				.then(function(data) {
					var p = nominatimToPayload(data);
					writePayloadToDom($root, p);
					updateMarkerFromPayload($root, p);
					if (typeof window.stoApplyDependentFieldVisibility === 'function') {
						window.stoApplyDependentFieldVisibility();
					}
				})
				.catch(function() {
					var p = readPayloadFromDom($root);
					p.lat = String(lat);
					p.lng = String(lng);
					writePayloadToDom($root, p);
					updateMarkerFromPayload($root, p);
					showGmapMsg($root, i18n.geocodeError || '');
				});
		});
	}

	function fitMapToNominatimHit($root, hit) {
		var d = $root.data('stoGmapState');
		if (!d || !d.map || !window.L || !hit || typeof hit !== 'object') {
			return;
		}
		var b = hit.boundingbox;
		if (b && b.length === 4) {
			var south = parseFloat(b[0]);
			var north = parseFloat(b[1]);
			var west = parseFloat(b[2]);
			var east = parseFloat(b[3]);
			if (isFinite(south) && isFinite(north) && isFinite(west) && isFinite(east)) {
				d.map.fitBounds(
					L.latLngBounds(L.latLng(south, west), L.latLng(north, east)),
					{ padding: [16, 16], maxZoom: 16, animate: true }
				);
				return;
			}
		}
		var la = parseFloat(hit.lat);
		var lo = parseFloat(hit.lon);
		if (isFinite(la) && isFinite(lo)) {
			d.map.setView(L.latLng(la, lo), 16, { animate: true });
		}
	}

	function searchAddress($root) {
		var $s = $root.find('[data-sto-gmap-search]').first();
		var q = String($s.val() != null ? $s.val() : '').trim();
		var i18n = $root.data('stoGmapI18n') || {};
		if (!q) {
			return;
		}
		var url =
			'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' +
			encodeURIComponent(q);
		enqueueNomRequest(function() {
			return fetchNom(url)
				.then(function(arr) {
					if (!arr || !arr.length) {
						showGmapMsg($root, i18n.geocodeError || '');
						return;
					}
					var hit = arr[0];
					var p = nominatimToPayload(hit);
					writePayloadToDom($root, p);
					updateMarkerFromPayload($root, p);
					fitMapToNominatimHit($root, hit);
					if (typeof window.stoApplyDependentFieldVisibility === 'function') {
						window.stoApplyDependentFieldVisibility();
					}
				})
				.catch(function() {
					showGmapMsg($root, i18n.geocodeError || '');
				});
		});
	}

	function initMapUi($root) {
		if (!window.L || !L.map) {
			return;
		}
		var $canvas = $root.find('[data-sto-gmap-canvas]').first();
		var el = $canvas.get(0);
		if (!el) {
			return;
		}
		var payload = readPayloadFromDom($root);
		var ll = parseLatLng(payload);
		var center = ll || { lat: 20, lng: 0 };
		var map = L.map(el, {
			scrollWheelZoom: true,
			attributionControl: true
		}).setView([center.lat, center.lng], ll ? 15 : 2);

		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution:
				'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
		}).addTo(map);

		var marker = L.marker([center.lat, center.lng], { draggable: true });
		if (ll) {
			marker.addTo(map);
		}

		$root.data('stoGmapState', { map: map, marker: marker });

		map.on('click', function(ev) {
			var latlng = ev.latlng;
			marker.setLatLng(latlng);
			marker.addTo(map);
			reverseGeocode($root, latlng.lat, latlng.lng);
		});

		marker.on('dragend', function() {
			var pos = marker.getLatLng();
			reverseGeocode($root, pos.lat, pos.lng);
		});

		$root.find('[data-sto-gmap-search]').first().on('keydown.stoGmap', function(ev) {
			if (ev.key === 'Enter' || ev.keyCode === 13) {
				ev.preventDefault();
				searchAddress($root);
			}
		});

		setTimeout(function() {
			map.invalidateSize(true);
			if (ll) {
				map.setView(L.latLng(ll.lat, ll.lng), 15);
			}
		}, 200);
	}

	function bindOne($root) {
		var raw = $root.attr('data-sto-gmap-i18n');
		try {
			$root.data('stoGmapI18n', raw ? JSON.parse(raw) : {});
		} catch (e1) {
			$root.data('stoGmapI18n', {});
		}

		if ($root.data('stoGmapBound')) {
			setTimeout(function() {
				resizeMap($root);
			}, 80);
			return;
		}
		$root.data('stoGmapBound', 1);

		function pushPayloadAndMarker(p, skipCoordReverse) {
			$root.find('.sto-gmap-value').first().val(JSON.stringify(p)).trigger('change');
			updateMarkerFromPayload($root, p);
			if (typeof window.stoApplyDependentFieldVisibility === 'function') {
				window.stoApplyDependentFieldVisibility();
			}
		}

		var lineTimer = null;
		var coordTimer = null;

		$root.on('input change', '[data-sto-gmap-search]', function() {
			var p = readPayloadFromDom($root);
			pushPayloadAndMarker(p);
		});

		$root.on('input change', '[data-sto-gmap-field]', function(ev) {
			var el = ev.target;
			var key = el && el.getAttribute ? el.getAttribute('data-sto-gmap-field') : '';

			if (key === 'lat' || key === 'lng') {
				clearTimeout(coordTimer);
				coordTimer = setTimeout(function() {
					var p = readPayloadFromDom($root);
					var ll = parseLatLng(p);
					if (ll) {
						updateMarkerFromPayload($root, p);
						$root.find('.sto-gmap-value').first().val(JSON.stringify(p)).trigger('change');
						reverseGeocode($root, ll.lat, ll.lng);
					} else {
						pushPayloadAndMarker(p);
					}
				}, 650);
				return;
			}

			clearTimeout(lineTimer);
			lineTimer = setTimeout(function() {
				var p = readPayloadFromDom($root);
				p.formatted_address = buildLineFromFields(p);
				writePayloadToDom($root, p);
				updateMarkerFromPayload($root, p);
				if (typeof window.stoApplyDependentFieldVisibility === 'function') {
					window.stoApplyDependentFieldVisibility();
				}
			}, 200);
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
