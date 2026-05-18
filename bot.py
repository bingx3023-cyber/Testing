import sqlite3
import random
import os
from pyrogram import Client, filters
from pyrogram.types import (
    ReplyKeyboardMarkup,
    KeyboardButton,
    InlineKeyboardMarkup,
    InlineKeyboardButton
)

API_ID = 123456
API_HASH = "API_HASH"
BOT_TOKEN = "BOT_TOKEN"

ADMIN_PASSWORD = "1390"
FORCE_CHANNEL = "h4x_top"
CARD_NUMBER = "6037991234567890"
CARD_OWNER = "VPN SHOP"

app = Client(
    "vpnshopbot",
    api_id=API_ID,
    api_hash=API_HASH,
    bot_token=BOT_TOKEN
)

conn = sqlite3.connect("data.db", check_same_thread=False)
c = conn.cursor()

c.execute("""
CREATE TABLE IF NOT EXISTS users(
    user_id INTEGER PRIMARY KEY,
    phone TEXT,
    invited_by INTEGER,
    referrals INTEGER DEFAULT 0,
    banned INTEGER DEFAULT 0,
    verified INTEGER DEFAULT 0
)
""")

c.execute("""
CREATE TABLE IF NOT EXISTS orders(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    volume TEXT,
    duration TEXT,
    amount TEXT,
    receipt_file_id TEXT,
    status TEXT DEFAULT 'pending'
)
""")

conn.commit()

user_steps = {}
admin_mode = {}


# ---------------- UTIL ----------------


def is_admin(user_id):
    return user_id in admin_mode


async def check_join(user_id):
    try:
        member = await app.get_chat_member(FORCE_CHANNEL, user_id)
        return member.status in ["member", "administrator", "owner"]
    except:
        return False


async def check_access(client, message):
    user_id = message.from_user.id

    c.execute("SELECT banned, verified FROM users WHERE user_id=?", (user_id,))
    user = c.fetchone()

    if user and user[0] == 1:
        await message.reply("شما بن شده‌اید")
        return False

    joined = await check_join(user_id)

    if not joined:
        kb = InlineKeyboardMarkup(
            [[InlineKeyboardButton("عضویت در کانال", url=f"https://t.me/{FORCE_CHANNEL}")]]
        )

        await message.reply(
            "برای استفاده از ربات باید حتما داخل کانال عضو شوید",
            reply_markup=kb
        )
        return False

    if not user:
        c.execute(
            "INSERT OR IGNORE INTO users(user_id, verified) VALUES(?, 0)",
            (user_id,)
        )
        conn.commit()

    c.execute("SELECT verified FROM users WHERE user_id=?", (user_id,))
    verified = c.fetchone()[0]

    if verified == 0:
        kb = ReplyKeyboardMarkup(
            [[KeyboardButton("تایید شماره", request_contact=True)]],
            resize_keyboard=True
        )

        await message.reply(
            "برای فعال شدن ربات باید شماره خود را تایید کنید",
            reply_markup=kb
        )
        return False

    return True


# ---------------- START ----------------


@app.on_message(filters.command("start"))
async def start(client, message):
    user_id = message.from_user.id

    args = message.text.split()

    invited_by = None

    if len(args) > 1:
        try:
            invited_by = int(args[1])
        except:
            pass

    c.execute("SELECT * FROM users WHERE user_id=?", (user_id,))
    user = c.fetchone()

    if not user:
        c.execute(
            "INSERT INTO users(user_id, invited_by) VALUES(?, ?)",
            (user_id, invited_by)
        )
        conn.commit()

    access = await check_access(client, message)

    if not access:
        return

    kb = ReplyKeyboardMarkup(
        [
            ["خرید سرویس"],
            ["تست رایگان"],
            ["لینک رفرال من"]
        ],
        resize_keyboard=True
    )

    await message.reply(
        "به فروشگاه VPN خوش آمدید",
        reply_markup=kb
    )


# ---------------- CONTACT ----------------


@app.on_message(filters.contact)
async def contact(client, message):
    user_id = message.from_user.id

    phone = message.contact.phone_number

    c.execute(
        "UPDATE users SET phone=?, verified=1 WHERE user_id=?",
        (phone, user_id)
    )
    conn.commit()

    c.execute("SELECT invited_by FROM users WHERE user_id=?", (user_id,))
    inviter = c.fetchone()[0]

    if inviter:
        c.execute(
            "UPDATE users SET referrals = referrals + 1 WHERE user_id=?",
            (inviter,)
        )
        conn.commit()

    kb = ReplyKeyboardMarkup(
        [
            ["خرید سرویس"],
            ["تست رایگان"],
            ["لینک رفرال من"]
        ],
        resize_keyboard=True
    )

    await message.reply(
        "شماره تایید شد و ربات فعال شد",
        reply_markup=kb
    )


# ---------------- REFERRAL ----------------


@app.on_message(filters.regex("لینک رفرال من"))
async def referral(client, message):
    access = await check_access(client, message)

    if not access:
        return

    user_id = message.from_user.id

    bot_info = await app.get_me()

    link = f"https://t.me/{bot_info.username}?start={user_id}"

    txt = f"""
برای دریافت تست رایگان ۵۰ مگ باید حداقل ۱۰ نفر را با لینک اختصاصی خود دعوت کنید.

شرایط:
- کاربر باید عضو کانال شود
- شماره خود را تایید کند
- فقط رفرال واقعی حساب می‌شود

لینک اختصاصی شما:

{link}
"""

    await message.reply(txt)


# ---------------- FREE TEST ----------------


@app.on_message(filters.regex("تست رایگان"))
async def free_test(client, message):
    access = await check_access(client, message)

    if not access:
        return

    user_id = message.from_user.id

    c.execute("SELECT referrals FROM users WHERE user_id=?", (user_id,))
    refs = c.fetchone()[0]

    if refs < 10:
        await message.reply(
            f"شما فعلا {refs} رفرال دارید و برای دریافت تست باید ۱۰ نفر دعوت کنید"
        )
        return

    await message.reply(
        "تست ۵۰ مگ شما:\n\nvmess://test-config"
    )


# ---------------- BUY ----------------


@app.on_message(filters.regex("خرید سرویس"))
async def buy(client, message):
    access = await check_access(client, message)

    if not access:
        return

    user_steps[message.from_user.id] = {
        "step": "volume"
    }

    await message.reply("حجم سرویس را وارد کنید")


@app.on_message(filters.text)
async def text_handler(client, message):
    user_id = message.from_user.id

    if user_id in admin_mode:
        if message.text.startswith("/ban"):
            try:
                uid = int(message.text.split()[1])

                c.execute("UPDATE users SET banned=1 WHERE user_id=?", (uid,))
                conn.commit()

                await message.reply("کاربر بن شد")
            except:
                await message.reply("خطا")
            return

        if message.text.startswith("/unban"):
            try:
                uid = int(message.text.split()[1])

                c.execute("UPDATE users SET banned=0 WHERE user_id=?", (uid,))
                conn.commit()

                await message.reply("کاربر آن بن شد")
            except:
                await message.reply("خطا")
            return

        if message.text.startswith("/orders"):
            c.execute("SELECT * FROM orders WHERE status='pending'")
            orders = c.fetchall()

            if not orders:
                await message.reply("سفارشی نیست")
                return

            for order in orders:
                oid = order[0]
                uid = order[1]
                volume = order[2]
                duration = order[3]
                amount = order[4]
                receipt = order[5]

                kb = InlineKeyboardMarkup([
                    [
                        InlineKeyboardButton("تایید", callback_data=f"approve_{oid}"),
                        InlineKeyboardButton("رد", callback_data=f"reject_{oid}")
                    ]
                ])

                await app.send_photo(
                    chat_id=user_id,
                    photo=receipt,
                    caption=f"""
ORDER #{oid}

USER: {uid}
VOLUME: {volume}
TIME: {duration}
AMOUNT: {amount}
""",
                    reply_markup=kb
                )
            return

    if user_id not in user_steps:
        return

    step = user_steps[user_id]["step"]

    if step == "volume":
        user_steps[user_id]["volume"] = message.text
        user_steps[user_id]["step"] = "duration"

        await message.reply("مدت زمان سرویس را وارد کنید")

    elif step == "duration":
        user_steps[user_id]["duration"] = message.text

        rand = random.randint(1000, 9999)
        amount = f"150.{rand}"

        user_steps[user_id]["amount"] = amount
        user_steps[user_id]["step"] = "receipt"

        await message.reply(
            f"""
مبلغ زیر را دقیقا واریز کنید:

{amount}

شماره کارت:
{CARD_NUMBER}

به نام:
{CARD_OWNER}

سپس عکس فیش را ارسال کنید
"""
        )


# ---------------- RECEIPT ----------------


@app.on_message(filters.photo)
async def receipt(client, message):
    user_id = message.from_user.id

    if user_id not in user_steps:
        return

    if user_steps[user_id]["step"] != "receipt":
        return

    file_id = message.photo.file_id

    volume = user_steps[user_id]["volume"]
    duration = user_steps[user_id]["duration"]
    amount = user_steps[user_id]["amount"]

    c.execute(
        "INSERT INTO orders(user_id, volume, duration, amount, receipt_file_id) VALUES(?,?,?,?,?)",
        (user_id, volume, duration, amount, file_id)
    )
    conn.commit()

    del user_steps[user_id]

    await message.reply(
        "فیش شما ثبت شد و منتظر تایید مدیریت است"
    )


# ---------------- ADMIN ----------------


@app.on_message(filters.command("admin"))
async def admin(client, message):
    user_id = message.from_user.id

    args = message.text.split()

    if len(args) < 2:
        await message.reply("رمز را وارد کنید")
        return

    if args[1] == ADMIN_PASSWORD:
        admin_mode[user_id] = True

        await message.reply(
            "پنل مدیریت فعال شد\n\n/orders\n/ban ID\n/unban ID"
        )
    else:
        await message.reply("رمز اشتباه است")


# ---------------- CALLBACKS ----------------


@app.on_callback_query()
async def callbacks(client, callback_query):
    data = callback_query.data

    admin_id = callback_query.from_user.id

    if not is_admin(admin_id):
        return

    if data.startswith("approve_"):
        oid = int(data.split("_")[1])

        c.execute("UPDATE orders SET status='approved' WHERE id=?", (oid,))
        conn.commit()

        c.execute("SELECT user_id FROM orders WHERE id=?", (oid,))
        uid = c.fetchone()[0]

        admin_mode[f"send_{admin_id}"] = uid

        await callback_query.message.reply(
            "پیام تایید + کانفیگ را ارسال کنید"
        )

    elif data.startswith("reject_"):
        oid = int(data.split("_")[1])

        c.execute("UPDATE orders SET status='rejected' WHERE id=?", (oid,))
        conn.commit()

        c.execute("SELECT user_id FROM orders WHERE id=?", (oid,))
        uid = c.fetchone()[0]

        admin_mode[f"send_{admin_id}"] = uid

        await callback_query.message.reply(
            "پیام رد سفارش را ارسال کنید"
        )


@app.on_message(filters.text & filters.private)
async def admin_sender(client, message):
    admin_id = message.from_user.id

    key = f"send_{admin_id}"

    if key not in admin_mode:
        return

    uid = admin_mode[key]

    try:
        await app.send_message(uid, message.text)
        await message.reply("ارسال شد")
    except:
        await message.reply("خطا در ارسال")

    del admin_mode[key]


print("BOT STARTED")
app.run()
