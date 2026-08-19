window.tpAssetTrackingMap = function (points) {
    return {
        points: points ?? [],
        map: null,

        init() {
            this.$nextTick(() => this.renderMap());
        },

        escapeHtml(value) {
            const el = document.createElement('div');
            el.textContent = String(value ?? '');

            return el.innerHTML;
        },

        renderMap() {
            if (! this.points.length || ! this.$refs.mapEl || ! window.L) {
                return;
            }

            this.map = window.L.map(this.$refs.mapEl, { scrollWheelZoom: false });
            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '\u00a9 OpenStreetMap',
                maxZoom: 18,
            }).addTo(this.map);

            const bounds = [];

            this.points.forEach((point, index) => {
                const label = this.escapeHtml(point.label);
                const at = point.at ? this.escapeHtml(point.at) : '';
                const seq = point.seq ?? (index + 1);
                const icon = window.L.divIcon({
                    className: 'tp-journey-marker',
                    html: '<span class="tp-journey-marker__seq">' + this.escapeHtml(seq) + '</span>',
                    iconSize: [28, 28],
                    iconAnchor: [14, 14],
                });

                window.L.marker([point.lat, point.lng], { icon })
                    .addTo(this.map)
                    .bindPopup('<strong>' + this.escapeHtml(seq) + '. ' + label + '</strong>' + (at ? '<br>' + at : ''));

                bounds.push([point.lat, point.lng]);
            });

            if (this.points.length > 1) {
                window.L.polyline(bounds, {
                    color: '#51BC8F',
                    weight: 3,
                    dashArray: '4 6',
                }).addTo(this.map);
            }

            this.map.fitBounds(bounds, { padding: [24, 24] });
            this.invalidate();
            setTimeout(() => this.invalidate(), 200);
        },

        invalidate() {
            if (this.map) {
                this.map.invalidateSize();
            }
        },
    };
};
