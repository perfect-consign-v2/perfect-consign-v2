(function () {
    var stateNode = document.getElementById('map-state');
    var canvas = document.getElementById('mall-map');
    var inspector = document.getElementById('map-inspector');
    if (!stateNode || !canvas || !inspector || !canvas.getContext) {
        return;
    }
    var state = JSON.parse(stateNode.textContent || '{}');
    var tiles = state.tiles || [];
    var context = canvas.getContext('2d');
    var zoom = 1;
    var selected = '';
    var fills = {
        available: '#dcfce7',
        occupied: '#fecaca',
        ending: '#fde68a',
        incoming: '#dbeafe'
    };
    var strokes = {
        available: '#166534',
        occupied: '#991b1b',
        ending: '#b45309',
        incoming: '#1d4ed8'
    };

    function draw() {
        var scale = 8 * zoom;
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.fillStyle = '#e7e2d6';
        context.fillRect(0, 0, state.floorWidth * scale, state.floorDepth * scale);
        context.strokeStyle = '#cfc6b8';
        context.lineWidth = 1;
        var foot;
        for (foot = 0; foot <= state.floorWidth; foot += 1) {
            context.beginPath();
            context.moveTo(foot * scale, 0);
            context.lineTo(foot * scale, state.floorDepth * scale);
            context.stroke();
        }
        for (foot = 0; foot <= state.floorDepth; foot += 1) {
            context.beginPath();
            context.moveTo(0, foot * scale);
            context.lineTo(state.floorWidth * scale, foot * scale);
            context.stroke();
        }
        tiles.forEach(function (tile) {
            var x = tile.x * scale;
            var y = tile.z * scale;
            var width = tile.width * scale;
            var depth = tile.depth * scale;
            context.fillStyle = fills[tile.status] || '#fff';
            context.strokeStyle = tile.id === selected ? '#111' : (strokes[tile.status] || '#333');
            context.lineWidth = tile.id === selected ? 3 : 1;
            context.fillRect(x, y, width, depth);
            context.strokeRect(x, y, width, depth);
            context.fillStyle = '#1a2230';
            context.font = '12px sans-serif';
            context.fillText(tile.label, x + 6, y + 18);
        });
    }

    function line(text) {
        var paragraph = document.createElement('p');
        paragraph.textContent = text;
        return paragraph;
    }

    function show(tile) {
        selected = tile.id;
        inspector.replaceChildren();
        var heading = document.createElement('h2');
        heading.textContent = tile.code;
        inspector.append(heading, line(tile.status), line(tile.vendor || 'No vendor'), line(tile.width + ' by ' + tile.depth + ' ft'));
        draw();
    }

    canvas.addEventListener('click', function (event) {
        var rect = canvas.getBoundingClientRect();
        var scale = 8 * zoom;
        var x = (event.clientX - rect.left) * (canvas.width / rect.width) / scale;
        var z = (event.clientY - rect.top) * (canvas.height / rect.height) / scale;
        tiles.forEach(function (tile) {
            if (x >= tile.x && x <= tile.x + tile.width && z >= tile.z && z <= tile.z + tile.depth) {
                show(tile);
            }
        });
    });
    document.querySelectorAll('[data-booth]').forEach(function (link) {
        link.addEventListener('click', function () {
            tiles.forEach(function (tile) {
                if (tile.id === link.getAttribute('data-booth')) {
                    show(tile);
                }
            });
        });
    });
    draw();
}());
