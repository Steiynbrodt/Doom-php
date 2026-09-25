<?php
/*
 * DOOM-ish raycaster in one PHP file.
 *
 * This is NOT the original DOOM engine. PHP provides the page/game data;
 * the realtime renderer runs in JavaScript in the browser.
 *
 * Freedoom artwork is loaded from the official Freedoom repository by default.
 * Freedoom is licensed under the BSD 3-Clause license:
 * https://github.com/freedoom/freedoom
 *
 * If you want local assets instead, create:
 *   assets/freedoom/sprites/
 *   assets/freedoom/patches/
 * and copy the matching Freedoom source-tree files there.
 */

$map = [
    [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1],
    [1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1],
    [1,0,2,2,2,0,0,0,1,1,1,0,0,0,0,1],
    [1,0,2,0,2,0,0,0,1,0,1,0,0,3,0,1],
    [1,0,2,0,0,0,0,0,1,0,1,0,0,3,0,1],
    [1,0,2,2,2,0,0,0,0,0,0,0,0,3,0,1],
    [1,0,0,0,0,0,1,1,1,0,0,0,0,3,0,1],
    [1,0,0,0,0,0,1,0,0,0,2,2,0,0,0,1],
    [1,0,0,1,1,0,1,0,0,0,2,0,0,0,0,1],
    [1,0,0,1,0,0,0,0,0,0,2,0,0,1,0,1],
    [1,0,0,1,0,0,0,0,0,0,2,2,0,1,0,1],
    [1,0,0,1,0,0,1,1,0,0,0,0,0,1,0,1],
    [1,0,0,0,0,0,1,0,0,0,0,3,3,3,0,1],
    [1,0,0,0,0,0,1,0,0,0,0,0,0,0,0,1],
    [1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1],
    [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1],
];

$enemies = [
    ['x' => 6.5,  'y' => 3.5],
    ['x' => 11.5, 'y' => 5.5],
    ['x' => 8.5,  'y' => 10.5],
    ['x' => 13.5, 'y' => 13.5],
    ['x' => 3.5,  'y' => 12.5],
];

$localAssets = is_dir(__DIR__ . '/assets/freedoom');
$assetBase = $localAssets
    ? './assets/freedoom/'
    : 'https://raw.githubusercontent.com/freedoom/freedoom/master/';

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PHP DOOM-ish</title>
<style>
    * { box-sizing: border-box; }
    html, body {
        width: 100%;
        height: 100%;
        margin: 0;
        overflow: hidden;
        background: #000;
        color: #fff;
        font-family: Consolas, "Courier New", monospace;
    }
    canvas {
        display: block;
        width: 100vw;
        height: 100vh;
        image-rendering: pixelated;
        cursor: crosshair;
    }
    #start {
        position: fixed;
        inset: 0;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: center;
        background:
            radial-gradient(circle at center, rgba(70,0,0,.25), rgba(0,0,0,.96));
        text-align: center;
        user-select: none;
        cursor: pointer;
    }
    #panel {
        width: min(760px, 90vw);
        border: 2px solid #6f1111;
        background: rgba(0,0,0,.82);
        padding: 30px;
        box-shadow: 0 0 40px #500;
    }
    h1 {
        margin: 0 0 10px;
        color: #d21919;
        font-size: clamp(42px, 8vw, 92px);
        line-height: .9;
        text-shadow: 3px 3px 0 #4d0000, 7px 7px 0 #111;
    }
    .small {
        opacity: .75;
        font-size: 12px;
        line-height: 1.5;
    }
    .controls {
        margin: 20px auto;
        font-size: 16px;
        line-height: 1.7;
    }
    .click {
        margin-top: 20px;
        color: #ffd54a;
        font-weight: bold;
        font-size: 20px;
        animation: blink 1s steps(2, end) infinite;
    }
    @keyframes blink { 50% { opacity: .35; } }
</style>
</head>
<body>

<div id="start">
    <div id="panel">
        <h1>PHP DOOM-ish</h1>
        <div class="controls">
            WASD = move &nbsp; • &nbsp; Mouse = look<br>
            Left click / Space = fire &nbsp; • &nbsp; Shift = run<br>
            R = restart after death / victory
        </div>
        <div class="click">CLICK TO ENTER</div>
        <p class="small">
            Uses free artwork from the Freedoom project.
            <?php if (!$localAssets): ?>
                Assets are being loaded from the official Freedoom GitHub repository.
            <?php else: ?>
                Using local Freedoom assets from <code>assets/freedoom/</code>.
            <?php endif; ?>
        </p>
    </div>
</div>

<canvas id="game"></canvas>

<script>
"use strict";

const map = <?= json_encode($map, JSON_THROW_ON_ERROR) ?>;
const MAP_H = map.length;
const MAP_W = map[0].length;

const initialEnemies = <?= json_encode($enemies, JSON_THROW_ON_ERROR) ?>;
const ASSET_BASE = <?= json_encode($assetBase, JSON_THROW_ON_ERROR) ?>;

const W = 640;
const H = 360;
const FOV = Math.PI / 3;

const canvas = document.getElementById("game");
const ctx = canvas.getContext("2d", { alpha: false });
canvas.width = W;
canvas.height = H;
ctx.imageSmoothingEnabled = false;

const startScreen = document.getElementById("start");

const keys = Object.create(null);
let lastTime = performance.now();
let depthBuffer = new Float32Array(W);
let started = false;

function image(path) {
    const img = new Image();
    img.src = ASSET_BASE + path;
    return img;
}

const assets = {
    walls: {
        1: image("patches/brick.png"),
        2: image("patches/stonew1.png"),
        3: image("patches/comp01_1.png"),
    },
    enemy: image("sprites/possa1.png"),
    gunIdle: image("sprites/shtga0.png"),
    gunFire: image("sprites/shtgb0.png"),
};

let player;
let enemies;

function resetGame() {
    player = {
        x: 1.5,
        y: 1.5,
        angle: 0,
        health: 100,
        ammo: 60,
        kills: 0,
        shootCooldown: 0,
        weaponKick: 0,
        damageFlash: 0,
    };

    enemies = initialEnemies.map((e, i) => ({
        id: i,
        x: e.x,
        y: e.y,
        health: 100,
        alive: true,
        attackCooldown: 0,
        hurtFlash: 0,
    }));
}

resetGame();

function normAngle(a) {
    while (a < -Math.PI) a += Math.PI * 2;
    while (a >  Math.PI) a -= Math.PI * 2;
    return a;
}

function distance(ax, ay, bx, by) {
    return Math.hypot(bx - ax, by - ay);
}

function tileAt(x, y) {
    const mx = Math.floor(x);
    const my = Math.floor(y);

    if (mx < 0 || my < 0 || mx >= MAP_W || my >= MAP_H) {
        return 1;
    }

    return map[my][mx];
}

function isWall(x, y) {
    return tileAt(x, y) !== 0;
}

function canMove(x, y, radius = 0.22) {
    return !isWall(x - radius, y - radius)
        && !isWall(x + radius, y - radius)
        && !isWall(x - radius, y + radius)
        && !isWall(x + radius, y + radius);
}

/*
 * DDA raycaster.
 * Returns perpendicular wall distance, wall type, texture coordinate, and side.
 */
function castRay(angle) {
    const rayDirX = Math.cos(angle);
    const rayDirY = Math.sin(angle);

    let mapX = Math.floor(player.x);
    let mapY = Math.floor(player.y);

    const deltaDistX = Math.abs(1 / (rayDirX || 1e-9));
    const deltaDistY = Math.abs(1 / (rayDirY || 1e-9));

    let stepX, stepY;
    let sideDistX, sideDistY;

    if (rayDirX < 0) {
        stepX = -1;
        sideDistX = (player.x - mapX) * deltaDistX;
    } else {
        stepX = 1;
        sideDistX = (mapX + 1 - player.x) * deltaDistX;
    }

    if (rayDirY < 0) {
        stepY = -1;
        sideDistY = (player.y - mapY) * deltaDistY;
    } else {
        stepY = 1;
        sideDistY = (mapY + 1 - player.y) * deltaDistY;
    }

    let side = 0;
    let wallType = 1;

    for (let i = 0; i < 128; i++) {
        if (sideDistX < sideDistY) {
            sideDistX += deltaDistX;
            mapX += stepX;
            side = 0;
        } else {
            sideDistY += deltaDistY;
            mapY += stepY;
            side = 1;
        }

        if (mapX < 0 || mapY < 0 || mapX >= MAP_W || mapY >= MAP_H) {
            break;
        }

        wallType = map[mapY][mapX];

        if (wallType !== 0) {
            break;
        }
    }

    let dist;
    if (side === 0) {
        dist = (mapX - player.x + (1 - stepX) / 2) / (rayDirX || 1e-9);
    } else {
        dist = (mapY - player.y + (1 - stepY) / 2) / (rayDirY || 1e-9);
    }

    dist = Math.max(0.001, Math.abs(dist));

    let wallX;
    if (side === 0) {
        wallX = player.y + dist * rayDirY;
    } else {
        wallX = player.x + dist * rayDirX;
    }
    wallX -= Math.floor(wallX);

    if (side === 0 && rayDirX > 0) wallX = 1 - wallX;
    if (side === 1 && rayDirY < 0) wallX = 1 - wallX;

    return { dist, wallType, wallX, side };
}

function updatePlayer(dt) {
    if (player.health <= 0 || player.kills === enemies.length) return;

    const speed = (keys["ShiftLeft"] || keys["ShiftRight"]) ? 4.6 : 2.7;

    let mx = 0;
    let my = 0;

    if (keys["KeyW"]) {
        mx += Math.cos(player.angle);
        my += Math.sin(player.angle);
    }
    if (keys["KeyS"]) {
        mx -= Math.cos(player.angle);
        my -= Math.sin(player.angle);
    }
    if (keys["KeyA"]) {
        mx += Math.cos(player.angle - Math.PI / 2);
        my += Math.sin(player.angle - Math.PI / 2);
    }
    if (keys["KeyD"]) {
        mx += Math.cos(player.angle + Math.PI / 2);
        my += Math.sin(player.angle + Math.PI / 2);
    }

    const len = Math.hypot(mx, my);
    if (len > 0) {
        mx /= len;
        my /= len;
    }

    const nx = player.x + mx * speed * dt;
    const ny = player.y + my * speed * dt;

    if (canMove(nx, player.y)) player.x = nx;
    if (canMove(player.x, ny)) player.y = ny;

    player.shootCooldown = Math.max(0, player.shootCooldown - dt);
    player.weaponKick = Math.max(0, player.weaponKick - dt * 7);
    player.damageFlash = Math.max(0, player.damageFlash - dt * 2.5);
}

function updateEnemies(dt) {
    if (player.health <= 0) return;

    for (const enemy of enemies) {
        if (!enemy.alive) continue;

        enemy.attackCooldown = Math.max(0, enemy.attackCooldown - dt);
        enemy.hurtFlash = Math.max(0, enemy.hurtFlash - dt * 4);

        const dx = player.x - enemy.x;
        const dy = player.y - enemy.y;
        const dist = Math.hypot(dx, dy);

        if (dist < 9 && dist > 0.78) {
            const move = 0.72 * dt;
            const nx = enemy.x + (dx / dist) * move;
            const ny = enemy.y + (dy / dist) * move;

            if (canMove(nx, enemy.y, 0.18)) enemy.x = nx;
            if (canMove(enemy.x, ny, 0.18)) enemy.y = ny;
        }

        if (dist < 0.9 && enemy.attackCooldown <= 0) {
            player.health = Math.max(0, player.health - 10);
            player.damageFlash = 1;
            enemy.attackCooldown = 0.9;
        }
    }
}

function shoot() {
    if (!started || player.health <= 0 || player.kills === enemies.length) return;
    if (player.shootCooldown > 0 || player.ammo <= 0) return;

    player.ammo--;
    player.shootCooldown = 0.32;
    player.weaponKick = 1;

    const wallAhead = castRay(player.angle).dist;

    let target = null;
    let targetDist = Infinity;

    for (const enemy of enemies) {
        if (!enemy.alive) continue;

        const dx = enemy.x - player.x;
        const dy = enemy.y - player.y;
        const dist = Math.hypot(dx, dy);

        let a = normAngle(Math.atan2(dy, dx) - player.angle);

        // Slightly forgiving hit cone, narrower with distance.
        const cone = 0.035 + 0.11 / Math.max(1, dist);

        if (Math.abs(a) > cone) continue;
        if (dist > wallAhead + 0.25) continue;

        // Check direct line to target against walls.
        const directRay = castRay(Math.atan2(dy, dx));
        if (dist > directRay.dist + 0.2) continue;

        if (dist < targetDist) {
            target = enemy;
            targetDist = dist;
        }
    }

    if (target) {
        const damage = targetDist < 3 ? 100 : 50;
        target.health -= damage;
        target.hurtFlash = 1;

        if (target.health <= 0) {
            target.alive = false;
            player.kills++;
        }
    }
}

function fallbackWallColor(type, side, dist) {
    const base = type === 1
        ? [150, 52, 34]
        : type === 2
            ? [110, 120, 125]
            : [95, 110, 70];

    const shade = Math.max(0.25, Math.min(1, 1.25 / (1 + dist * 0.09))) * (side ? 0.78 : 1);
    return `rgb(${base.map(v => Math.floor(v * shade)).join(",")})`;
}

function renderWorld() {
    // Ceiling
    const sky = ctx.createLinearGradient(0, 0, 0, H / 2);
    sky.addColorStop(0, "#090b0d");
    sky.addColorStop(1, "#24201c");
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, W, H / 2);

    // Floor
    const floor = ctx.createLinearGradient(0, H / 2, 0, H);
    floor.addColorStop(0, "#3b352c");
    floor.addColorStop(1, "#0e0c0a");
    ctx.fillStyle = floor;
    ctx.fillRect(0, H / 2, W, H / 2);

    for (let x = 0; x < W; x++) {
        const cameraX = (2 * x / W) - 1;
        const rayAngle = player.angle + Math.atan(cameraX * Math.tan(FOV / 2));

        const ray = castRay(rayAngle);

        // Correct fisheye distortion.
        const corrected = ray.dist * Math.cos(rayAngle - player.angle);
        depthBuffer[x] = corrected;

        const lineHeight = Math.min(H * 4, H / corrected);
        const top = Math.floor(H / 2 - lineHeight / 2);

        const tex = assets.walls[ray.wallType];

        if (tex && tex.complete && tex.naturalWidth > 0) {
            const tx = Math.max(0, Math.min(tex.naturalWidth - 1, Math.floor(ray.wallX * tex.naturalWidth)));

            ctx.globalAlpha = ray.side ? 0.78 : 1;
            ctx.drawImage(
                tex,
                tx, 0, 1, tex.naturalHeight,
                x, top, 1, lineHeight
            );
            ctx.globalAlpha = 1;

            // Distance darkening.
            const darkness = Math.min(0.72, corrected / 18);
            if (darkness > 0) {
                ctx.fillStyle = `rgba(0,0,0,${darkness})`;
                ctx.fillRect(x, top, 1, lineHeight);
            }
        } else {
            ctx.fillStyle = fallbackWallColor(ray.wallType, ray.side, corrected);
            ctx.fillRect(x, top, 1, lineHeight);
        }
    }
}

function renderEnemies() {
    const visible = [];

    for (const enemy of enemies) {
        if (!enemy.alive) continue;

        const dx = enemy.x - player.x;
        const dy = enemy.y - player.y;
        const dist = Math.hypot(dx, dy);
        const angle = normAngle(Math.atan2(dy, dx) - player.angle);

        if (Math.abs(angle) < FOV / 2 + 0.35) {
            visible.push({ enemy, dist, angle });
        }
    }

    visible.sort((a, b) => b.dist - a.dist);

    const planeDist = W / (2 * Math.tan(FOV / 2));

    for (const obj of visible) {
        const corrected = obj.dist * Math.cos(obj.angle);
        const screenX = W / 2 + Math.tan(obj.angle) * planeDist;

        const centerX = Math.floor(screenX);
        if (centerX < 0 || centerX >= W) continue;
        if (corrected > depthBuffer[centerX] + 0.12) continue;

        const spriteHeight = Math.min(H * 1.4, 175 / corrected);
        const spriteWidth = spriteHeight * 0.72;
        const left = screenX - spriteWidth / 2;
        const top = H / 2 - spriteHeight / 2;

        if (assets.enemy.complete && assets.enemy.naturalWidth > 0) {
            ctx.globalAlpha = Math.max(0.45, 1 - corrected / 22);

            if (obj.enemy.hurtFlash > 0) {
                ctx.globalAlpha = .45;
            }

            ctx.drawImage(assets.enemy, left, top, spriteWidth, spriteHeight);
            ctx.globalAlpha = 1;
        } else {
            ctx.fillStyle = obj.enemy.hurtFlash > 0 ? "#fff" : "#8f1818";
            ctx.fillRect(left, top, spriteWidth, spriteHeight);
        }

        // Health bar
        const barW = Math.max(14, spriteWidth * .75);
        const barX = screenX - barW / 2;
        const barY = top - 7;

        ctx.fillStyle = "rgba(0,0,0,.85)";
        ctx.fillRect(barX, barY, barW, 4);

        ctx.fillStyle = "#45d044";
        ctx.fillRect(barX, barY, barW * Math.max(0, obj.enemy.health / 100), 4);
    }
}

function renderWeapon() {
    const firing = player.shootCooldown > 0.20;
    const gun = firing ? assets.gunFire : assets.gunIdle;

    const kick = player.weaponKick * 17;

    if (gun.complete && gun.naturalWidth > 0) {
        const scale = 2.7;
        const dw = gun.naturalWidth * scale;
        const dh = gun.naturalHeight * scale;

        ctx.drawImage(
            gun,
            W / 2 - dw / 2,
            H - dh + kick,
            dw,
            dh
        );
    } else {
        // Fallback if remote images are blocked/unavailable.
        ctx.fillStyle = "#3b3b3b";
        ctx.fillRect(W / 2 - 45, H - 105 + kick, 90, 105);
        ctx.fillStyle = "#777";
        ctx.fillRect(W / 2 - 20, H - 145 + kick, 40, 85);
    }

    if (firing) {
        ctx.fillStyle = "rgba(255,225,90,.28)";
        ctx.fillRect(0, 0, W, H);
    }
}

function renderCrosshair() {
    const x = W / 2;
    const y = H / 2;

    ctx.strokeStyle = "rgba(255,255,255,.9)";
    ctx.lineWidth = 1;

    ctx.beginPath();
    ctx.moveTo(x - 8, y);
    ctx.lineTo(x - 3, y);
    ctx.moveTo(x + 3, y);
    ctx.lineTo(x + 8, y);
    ctx.moveTo(x, y - 8);
    ctx.lineTo(x, y - 3);
    ctx.moveTo(x, y + 3);
    ctx.lineTo(x, y + 8);
    ctx.stroke();
}

function renderHUD() {
    ctx.fillStyle = "rgba(0,0,0,.72)";
    ctx.fillRect(0, H - 36, W, 36);

    ctx.font = "bold 17px monospace";
    ctx.textBaseline = "middle";

    ctx.fillStyle = player.health > 30 ? "#ff4141" : "#ff0000";
    ctx.fillText(`HEALTH ${player.health}%`, 14, H - 18);

    ctx.fillStyle = player.ammo > 0 ? "#ffd23f" : "#ff3434";
    ctx.fillText(`SHELLS ${player.ammo}`, 190, H - 18);

    ctx.fillStyle = "#ddd";
    ctx.fillText(`KILLS ${player.kills}/${enemies.length}`, 355, H - 18);

    ctx.fillStyle = "#aaa";
    ctx.font = "11px monospace";
    ctx.fillText("FREEDOOM ASSETS", 520, H - 18);
}

function renderMinimap() {
    const s = 5;
    const ox = 7;
    const oy = 7;

    ctx.fillStyle = "rgba(0,0,0,.67)";
    ctx.fillRect(ox - 2, oy - 2, MAP_W * s + 4, MAP_H * s + 4);

    for (let y = 0; y < MAP_H; y++) {
        for (let x = 0; x < MAP_W; x++) {
            if (map[y][x]) {
                ctx.fillStyle = map[y][x] === 1 ? "#744035"
                    : map[y][x] === 2 ? "#70777c"
                    : "#657344";
                ctx.fillRect(ox + x * s, oy + y * s, s, s);
            }
        }
    }

    ctx.fillStyle = "#e33";
    for (const enemy of enemies) {
        if (!enemy.alive) continue;
        ctx.fillRect(ox + enemy.x * s - 1, oy + enemy.y * s - 1, 3, 3);
    }

    ctx.fillStyle = "#5aff63";
    ctx.fillRect(ox + player.x * s - 2, oy + player.y * s - 2, 4, 4);

    ctx.strokeStyle = "#5aff63";
    ctx.beginPath();
    ctx.moveTo(ox + player.x * s, oy + player.y * s);
    ctx.lineTo(
        ox + player.x * s + Math.cos(player.angle) * 9,
        oy + player.y * s + Math.sin(player.angle) * 9
    );
    ctx.stroke();
}

function renderStateOverlay() {
    if (player.damageFlash > 0) {
        ctx.fillStyle = `rgba(170,0,0,${player.damageFlash * .30})`;
        ctx.fillRect(0, 0, W, H);
    }

    let title = "";
    let color = "#fff";

    if (player.health <= 0) {
        title = "YOU DIED";
        color = "#ff1b1b";
    } else if (player.kills === enemies.length) {
        title = "AREA CLEAR";
        color = "#71ff68";
    }

    if (!title) return;

    ctx.fillStyle = "rgba(0,0,0,.64)";
    ctx.fillRect(0, 0, W, H);

    ctx.textAlign = "center";
    ctx.textBaseline = "middle";

    ctx.font = "bold 46px monospace";
    ctx.fillStyle = color;
    ctx.fillText(title, W / 2, H / 2 - 10);

    ctx.font = "16px monospace";
    ctx.fillStyle = "#ddd";
    ctx.fillText("Press R to restart", W / 2, H / 2 + 38);

    ctx.textAlign = "left";
}

function frame(now) {
    const dt = Math.min(0.05, (now - lastTime) / 1000);
    lastTime = now;

    if (started) {
        updatePlayer(dt);
        updateEnemies(dt);
    }

    renderWorld();
    renderEnemies();
    renderCrosshair();
    renderWeapon();
    renderHUD();
    renderMinimap();
    renderStateOverlay();

    requestAnimationFrame(frame);
}

document.addEventListener("keydown", e => {
    keys[e.code] = true;

    if (e.code === "Space") {
        e.preventDefault();
        shoot();
    }

    if (e.code === "KeyR" && (player.health <= 0 || player.kills === enemies.length)) {
        resetGame();
    }
});

document.addEventListener("keyup", e => {
    keys[e.code] = false;
});

document.addEventListener("mousemove", e => {
    if (document.pointerLockElement !== canvas) return;
    if (!started || player.health <= 0) return;
    player.angle += e.movementX * 0.0022;
});

canvas.addEventListener("mousedown", e => {
    if (e.button === 0) shoot();
    if (document.pointerLockElement !== canvas && started) {
        canvas.requestPointerLock();
    }
});

startScreen.addEventListener("click", () => {
    started = true;
    startScreen.style.display = "none";
    canvas.requestPointerLock();
});

requestAnimationFrame(frame);
</script>

</body>
</html>
